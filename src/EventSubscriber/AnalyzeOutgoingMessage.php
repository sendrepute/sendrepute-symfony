<?php

declare(strict_types=1);

namespace SendRepute\Symfony\EventSubscriber;

use SendRepute\Symfony\ClassificationOutcome;
use SendRepute\Symfony\ClassifierInterface;
use SendRepute\Symfony\Exception\SendReputeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;
use Throwable;
use UnexpectedValueException;

final class AnalyzeOutgoingMessage implements EventSubscriberInterface
{
    public function __construct(
        private readonly ClassifierInterface $classifier,
        private readonly EventDispatcherInterface $events,
        private readonly bool $enabled,
        private readonly bool $paidAnalysisConsent,
        private readonly string $mode,
        private readonly string $failurePolicy,
        private readonly float $spamProbabilityThreshold,
        private readonly string $optInHeader,
        private readonly ?string $model,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [MessageEvent::class => ['onMessage', 0]];
    }

    public function onMessage(MessageEvent $event): void
    {
        if ($event->isQueued()) {
            return;
        }
        $message = $event->getMessage();
        if (!$message instanceof Email || !$this->isOptedIn($message)) {
            return;
        }
        try {
            $this->validatePolicy();
            if (!$this->enabled || !$this->paidAnalysisConsent) {
                throw new SendReputeException('consent_required', 'The paid adapter is disabled or lacks explicit paid consent.');
            }
            [$sender, $subject, $body] = $this->classificationInput($message);
            $classification = $this->classifier->classify($sender, $subject, $body, $this->model);
        } catch (Throwable $error) {
            $blocked = $this->failurePolicy === 'block';
            if ($blocked) {
                $event->reject();
            }
            $this->reportFailure($error, $blocked);

            return;
        }

        $blocked = $this->mode === 'blocking'
            && $classification->spamProbability >= $this->spamProbabilityThreshold;
        if ($blocked) {
            $event->reject();
        }
        $this->report(new ClassificationOutcome(
            'classification',
            $blocked ? 'blocked' : 'allowed',
            $classification->spamProbability,
            requestId: $classification->requestId,
        ));
    }

    private function isOptedIn(Email $message): bool
    {
        if ($this->optInHeader === '' || preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $this->optInHeader) !== 1) {
            return false;
        }
        $header = $message->getHeaders()->get($this->optInHeader);

        return $header !== null && strtolower(trim($header->getBodyAsString())) === 'yes';
    }

    private function validatePolicy(): void
    {
        if (!in_array($this->mode, ['advisory', 'blocking'], true)
            || !in_array($this->failurePolicy, ['allow', 'block'], true)
            || !is_finite($this->spamProbabilityThreshold)
            || $this->spamProbabilityThreshold < 0 || $this->spamProbabilityThreshold > 1
            || ($this->model !== null && !in_array($this->model, ['thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'], true))) {
            throw new UnexpectedValueException('Invalid SendRepute mail policy.');
        }
    }

    /** @return array{string, string, string} */
    private function classificationInput(Email $message): array
    {
        $from = $message->getFrom()[0] ?? null;
        $sender = $from?->getName();
        $subject = $message->getSubject();
        if (!is_string($sender) || trim($sender) === '' || !is_string($subject) || trim($subject) === '') {
            throw new UnexpectedValueException('A sender display name and subject are required.');
        }
        $text = $message->getTextBody();
        $html = $message->getHtmlBody();
        if (($text !== null && !is_string($text)) || ($html !== null && !is_string($html))) {
            throw new UnexpectedValueException('Path-backed, resource-backed, and non-string bodies are unsupported.');
        }
        if ($text !== null && $html !== null) {
            // The endpoint normalizes one body string. Concatenation is unsafe:
            // markup in one fragment can consume a later MIME alternative.
            throw new UnexpectedValueException('Messages with both text and HTML alternatives are unsupported.');
        }
        $body = $text ?? $html;
        if (!is_string($body) || $body === '') {
            throw new UnexpectedValueException('Exactly one non-empty in-memory text or HTML body is required.');
        }
        foreach ($message->getAttachments() as $part) {
            if ($part->getMediaType() === 'text') {
                throw new UnexpectedValueException('Inline or attached textual MIME parts are unsupported.');
            }
        }
        if ($this->ambiguousBody($body, $text !== null)) {
            throw new UnexpectedValueException('The displayed body is ambiguous to the API normalizer.');
        }

        return [trim($sender), $subject, $body];
    }

    private function ambiguousBody(string $body, bool $plainText): bool
    {
        // visibleEmailText applies these transformations globally before its
        // final text extraction. Refuse syntax that can decode into markup or
        // let its CSS-brace rule erase displayed content.
        if (str_contains($body, '{') || str_contains($body, '}')
            || preg_match('/=\r?\n|=[0-9A-Fa-f]{2}/', $body) === 1
            || preg_match('/content-transfer-encoding\s*:\s*base64/i', $body) === 1
            || ($plainText && (str_contains($body, '<') || str_contains($body, '>')))) {
            return true;
        }
        $trimmed = trim($body);

        return strlen($trimmed) >= 80
            && preg_match('/\s{2,}/', $trimmed) !== 1
            && preg_match('/\A[A-Za-z0-9+\/=\r\n]+\z/', $trimmed) === 1;
    }

    private function reportFailure(Throwable $error, bool $blocked): void
    {
        if ($error instanceof SendReputeException) {
            $this->report(new ClassificationOutcome(
                'api_failure',
                $blocked ? 'blocked' : 'allowed',
                category: $error->category,
                status: $error->status,
                apiCode: $error->apiCode,
                requestId: $error->requestId,
            ));

            return;
        }
        $this->report(new ClassificationOutcome(
            'api_failure',
            $blocked ? 'blocked' : 'allowed',
            category: $error instanceof UnexpectedValueException ? 'malformed_message' : 'internal',
        ));
    }

    private function report(ClassificationOutcome $outcome): void
    {
        try {
            $this->events->dispatch($outcome);
        } catch (Throwable) {
        }
    }
}