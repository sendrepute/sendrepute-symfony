<?php

declare(strict_types=1);

namespace SendRepute\Symfony\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SendRepute\Symfony\ClassificationResult;
use SendRepute\Symfony\ClassifierInterface;
use SendRepute\Symfony\EventSubscriber\AnalyzeOutgoingMessage;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class AnalyzeOutgoingMessageTest extends TestCase
{
    public function testUnmarkedCriticalMailIsNeverAnalyzedOrChanged(): void
    {
        $classifier = $this->createMock(ClassifierInterface::class);
        $classifier->expects(self::never())->method('classify');
        $message = $this->email();
        $event = $this->event($message);

        $this->listener($classifier)->onMessage($event);

        self::assertFalse($event->isRejected());
        self::assertSame($message, $event->getMessage());
        self::assertSame('Visible body', $message->getTextBody());
    }

    public function testAdvisoryAllowsSpamAndPreservesMessage(): void
    {
        $classifier = $this->classifier(0.99);
        $message = $this->marked($this->email());
        $event = $this->event($message);

        $this->listener($classifier, 'advisory')->onMessage($event);

        self::assertFalse($event->isRejected());
        self::assertSame($message, $event->getMessage());
        self::assertSame('yes', $message->getHeaders()->get('X-SendRepute-Classify')?->getBodyAsString());
    }

    public function testBlockingRejectsThroughOfficialMessageEvent(): void
    {
        $event = $this->event($this->marked($this->email()));
        $this->listener($this->classifier(0.8), 'blocking', 'allow', 0.8)->onMessage($event);

        self::assertTrue($event->isRejected());
    }

    public function testIndependentFailurePolicyCanFailClosedInAdvisoryMode(): void
    {
        $classifier = $this->createMock(ClassifierInterface::class);
        $classifier->method('classify')->willThrowException(new \RuntimeException('offline'));
        $event = $this->event($this->marked($this->email()));

        $this->listener($classifier, 'advisory', 'block')->onMessage($event);

        self::assertTrue($event->isRejected());
    }

    public function testMixedAlternativesAreRejectedBeforePaidCall(): void
    {
        $classifier = $this->createMock(ClassifierInterface::class);
        $classifier->expects(self::never())->method('classify');
        $message = $this->marked($this->email()->text('benign')->html('<p>malicious offer</p>'));
        $event = $this->event($message);

        $this->listener($classifier, 'blocking', 'block')->onMessage($event);

        self::assertTrue($event->isRejected());
    }

    #[DataProvider('ambiguousBody')]
    public function testNormalizerAmbiguityIsRejectedBeforePaidCall(string $body, bool $html = false): void
    {
        $classifier = $this->createMock(ClassifierInterface::class);
        $classifier->expects(self::never())->method('classify');
        $message = $this->email()->text($html ? null : $body);
        if ($html) {
            $message->html($body);
        }
        $event = $this->event($this->marked($message));

        $this->listener($classifier, 'blocking', 'block')->onMessage($event);

        self::assertTrue($event->isRejected());
    }

    public static function ambiguousBody(): iterable
    {
        yield 'raw markup can swallow later text' => ['safe <style> hidden spam'];
        $fixtures = json_decode((string) file_get_contents(__DIR__.'/fixtures/normalizer-hazards.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            yield $fixture['name'].' exact bridge payload' => [$fixture['body']];
        }
        yield 'HTML quoted printable is globally decoded' => ['<p>safe=20offer</p>', true];
        yield 'HTML CSS braces can erase later content' => ['<p>safe</p> { spam offer } tail', true];
    }

    public function testOrdinaryBracesFreeHtmlIsClassifiedUnchanged(): void
    {
        $classifier = $this->createMock(ClassifierInterface::class);
        $classifier->expects(self::once())->method('classify')
            ->with('Sender Name', 'Account notice', '<p>Ordinary &amp; safe</p>', null)
            ->willReturn(new ClassificationResult('req-1', 'thor', 'inbox', 0.1, 'high', false, 5));
        $message = $this->email()->text(null)->html('<p>Ordinary &amp; safe</p>');
        $event = $this->event($this->marked($message));

        $this->listener($classifier, 'blocking', 'block')->onMessage($event);

        self::assertFalse($event->isRejected());
        self::assertSame('<p>Ordinary &amp; safe</p>', $message->getHtmlBody());
    }

    public function testInlineTextualDataPartIsRejectedBeforePaidCall(): void
    {
        $classifier = $this->createMock(ClassifierInterface::class);
        $classifier->expects(self::never())->method('classify');
        $message = $this->marked($this->email()->addPart(
            (new DataPart('second displayed text', 'inline.txt', 'text/plain'))->asInline(),
        ));
        $event = $this->event($message);

        $this->listener($classifier, 'blocking', 'block')->onMessage($event);

        self::assertTrue($event->isRejected());
    }

    public function testQueuedCloneIsSkippedToPreventDoubleCharge(): void
    {
        $classifier = $this->createMock(ClassifierInterface::class);
        $classifier->expects(self::never())->method('classify');
        $message = $this->marked($this->email());
        $event = new MessageEvent($message, Envelope::create($message), 'async', true);

        $this->listener($classifier)->onMessage($event);

        self::assertFalse($event->isRejected());
    }

    public function testRealMailerTransportDoesNotSendRejectedMessage(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->listener($this->classifier(1.0), 'blocking'));
        $transport = new class($dispatcher) extends AbstractTransport {
            public int $sent = 0;

            protected function doSend(SentMessage $message): void
            {
                ++$this->sent;
            }

            public function __toString(): string
            {
                return 'fixture://';
            }
        };
        $mailer = new Mailer($transport);

        $mailer->send($this->marked($this->email()));

        self::assertSame(0, $transport->sent);
    }

    private function email(): Email
    {
        return (new Email())
            ->from('Sender Name <sender@example.test>')
            ->to('recipient@example.test')
            ->subject('Account notice')
            ->text('Visible body');
    }

    private function marked(Email $email): Email
    {
        $email->getHeaders()->addTextHeader('X-SendRepute-Classify', 'yes');

        return $email;
    }

    private function event(Email $email): MessageEvent
    {
        return new MessageEvent($email, Envelope::create($email), 'test', false);
    }

    private function classifier(float $score): ClassifierInterface
    {
        $classifier = $this->createMock(ClassifierInterface::class);
        $classifier->method('classify')->willReturn(
            new ClassificationResult('req-1', 'thor', 'spam', $score, 'high', false, 5),
        );

        return $classifier;
    }

    private function listener(
        ClassifierInterface $classifier,
        string $mode = 'advisory',
        string $failure = 'allow',
        float $threshold = 0.8,
    ): AnalyzeOutgoingMessage {
        return new AnalyzeOutgoingMessage(
            $classifier,
            new EventDispatcher(),
            true,
            true,
            $mode,
            $failure,
            $threshold,
            'X-SendRepute-Classify',
            null,
        );
    }
}