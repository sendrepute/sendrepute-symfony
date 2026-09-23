<?php

declare(strict_types=1);

namespace SendRepute\Symfony;

use JsonException;
use SendRepute\Symfony\Exception\SendReputeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class SendReputeClassifier implements ClassifierInterface
{
    public const ENDPOINT = 'https://www.sendrepute.com/api/v1/classify';
    private const MAX_RESPONSE_BYTES = 1_048_576;
    private const MODELS = ['thor', 'theos', 'athena', 'odin', 'freya', 'hermes', 'ares', 'apollo'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiToken,
        private readonly bool $paidAnalysisConsent,
        private readonly float $connectTimeout = 3.0,
        private readonly float $requestTimeout = 10.0,
    ) {
    }

    public function classify(string $sender, string $subject, string $body, ?string $model = null): ClassificationResult
    {
        if (!$this->paidAnalysisConsent) {
            throw new SendReputeException('consent_required', 'Paid SendRepute analysis has not been explicitly enabled.');
        }
        $this->validateInput($sender, $subject, $body, $model);
        if (trim($this->apiToken) === '' || preg_match('/[\r\n]/', $this->apiToken)) {
            throw new SendReputeException('configuration', 'A valid server-side SendRepute API token is required.');
        }
        if (!is_finite($this->connectTimeout) || $this->connectTimeout < 0.1 || $this->connectTimeout > 30.0
            || !is_finite($this->requestTimeout) || $this->requestTimeout < 0.1 || $this->requestTimeout > 60.0) {
            throw new SendReputeException('configuration', 'HTTP deadlines are outside their allowed range.');
        }

        $payload = ['sender' => $sender, 'subject' => $subject, 'body' => $body];
        if ($model !== null) {
            $payload['model'] = $model;
        }

        try {
            $response = $this->http->request('POST', self::ENDPOINT, [
                'auth_bearer' => $this->apiToken,
                'headers' => ['Accept' => 'application/json'],
                'json' => $payload,
                'max_redirects' => 0,
                'timeout' => $this->connectTimeout,
                'max_duration' => $this->requestTimeout,
            ]);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            if ($status >= 300 && $status < 400) {
                $response->cancel();
                throw new SendReputeException('transport', 'SendRepute redirects are refused to protect credentials.', $status);
            }
            $length = $headers['content-length'][0] ?? null;
            if (is_string($length) && ctype_digit($length) && (int) $length > self::MAX_RESPONSE_BYTES) {
                $response->cancel();
                throw new SendReputeException('malformed_response', 'SendRepute response exceeded 1 MiB.', $status);
            }
            $stream = $response->toStream(false);
            $raw = '';
            $deadline = hrtime(true) + (int) ($this->requestTimeout * 1_000_000_000);
            while (!feof($stream)) {
                if (hrtime(true) > $deadline) {
                    $response->cancel();
                    throw new SendReputeException('transport', 'SendRepute response body timed out.', $status);
                }
                $chunk = fread($stream, min(8192, self::MAX_RESPONSE_BYTES - strlen($raw) + 1));
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    $response->cancel();
                    throw new SendReputeException('transport', 'SendRepute response body could not be read.', $status);
                }
                $raw .= $chunk;
                if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
                    $response->cancel();
                    throw new SendReputeException('malformed_response', 'SendRepute response exceeded 1 MiB.', $status);
                }
            }
        } catch (SendReputeException $error) {
            throw $error;
        } catch (Throwable) {
            throw new SendReputeException('transport', 'SendRepute request failed before a valid response was received.');
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SendReputeException('malformed_response', 'SendRepute returned invalid JSON.', $status);
        }
        if ($status < 200 || $status >= 300) {
            throw $this->apiError($status, is_array($decoded) ? $decoded : []);
        }
        if (!is_array($decoded)) {
            throw new SendReputeException('malformed_response', 'SendRepute returned an invalid response object.', $status);
        }

        return $this->result($decoded);
    }

    private function validateInput(string $sender, string $subject, string $body, ?string $model): void
    {
        foreach ([[$sender, 320, 'sender'], [$subject, 998, 'subject'], [$body, 524288, 'body']] as [$value, $limit, $field]) {
            if ($value === '' || strlen($value) > $limit || preg_match('//u', $value) !== 1) {
                throw new SendReputeException('malformed_request', "{$field} must be non-empty UTF-8 and at most {$limit} bytes.");
            }
        }
        if ($model !== null && !in_array($model, self::MODELS, true)) {
            throw new SendReputeException('malformed_request', 'The selected model is not supported.');
        }
    }

    private function apiError(int $status, array $decoded): SendReputeException
    {
        $code = $this->safeIdentifier($decoded['error']['code'] ?? null);
        $requestId = $this->safeIdentifier($decoded['requestId'] ?? null);
        $category = match ($status) {
            400, 413 => 'malformed_request',
            401, 403 => 'authentication',
            402 => 'balance',
            429 => 'rate_limit',
            409, 503 => 'unavailable',
            default => 'api',
        };

        return new SendReputeException($category, "SendRepute request failed with HTTP status {$status}.", $status, $code, $requestId);
    }

    private function safeIdentifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $value) === 1 ? $value : null;
    }

    private function result(array $value): ClassificationResult
    {
        $result = $value['result'] ?? null;
        $billing = $value['billing'] ?? null;
        $score = is_array($result) ? ($result['spamProbability'] ?? null) : null;
        $charged = is_array($billing) ? ($billing['chargedMillicents'] ?? null) : null;
        if (!is_array($result) || !is_array($billing)
            || !$this->hasOnlyKeys($value, ['requestId', 'model', 'result', 'billing'])
            || !$this->hasOnlyKeys($billing, ['chargedMillicents', 'replayed'])
            || !$this->hasOnlyKeys($result, ['label', 'spamProbability', 'flaggedTermCount', 'confidence', 'reasons', 'flaggedTerms', 'analyzedFields', 'modelVersion', 'analyzedAt', 'contentAudit'])
            || !is_string($value['requestId'] ?? null) || $this->safeIdentifier($value['requestId']) === null
            || !is_string($value['model'] ?? null) || !in_array($value['model'], self::MODELS, true)
            || !in_array($result['label'] ?? null, ['inbox', 'spam'], true)
            || !in_array($result['confidence'] ?? null, ['low', 'medium', 'high'], true)
            || (!is_int($score) && !is_float($score)) || !is_finite((float) $score) || $score < 0 || $score > 1
            || !$this->validReasons($result['reasons'] ?? null)
            || !$this->stringList($result['flaggedTerms'] ?? null)
            || !$this->stringList($result['analyzedFields'] ?? null)
            || !is_string($result['modelVersion'] ?? null)
            || !is_string($result['analyzedAt'] ?? null)
            || (array_key_exists('flaggedTermCount', $result) && !$this->nonNegativeInteger($result['flaggedTermCount']))
            || (array_key_exists('contentAudit', $result) && !$this->validContentAudit($result['contentAudit']))
            || !is_bool($billing['replayed'] ?? null)
            || !$this->nonNegativeInteger($charged)) {
            throw new SendReputeException('malformed_response', 'SendRepute returned an invalid classification result.');
        }

        return new ClassificationResult(
            $value['requestId'],
            $value['model'],
            $result['label'],
            (float) $score,
            $result['confidence'],
            $billing['replayed'],
            (int) $charged,
        );
    }

    private function hasOnlyKeys(array $value, array $allowed): bool
    {
        return array_diff(array_keys($value), $allowed) === [];
    }

    private function nonNegativeInteger(mixed $value, ?int $maximum = null): bool
    {
        return (is_int($value) || is_float($value))
            && is_finite((float) $value)
            && $value >= 0
            && floor((float) $value) === (float) $value
            && ($maximum === null || $value <= $maximum);
    }

    private function stringList(mixed $value, ?int $maximumItems = null, ?int $maximumLength = null): bool
    {
        if (!is_array($value) || !array_is_list($value) || ($maximumItems !== null && count($value) > $maximumItems)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || ($maximumLength !== null && mb_strlen($item, 'UTF-8') > $maximumLength)) {
                return false;
            }
        }

        return true;
    }

    private function validReasons(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $reason) {
            if (!is_array($reason) || !$this->hasOnlyKeys($reason, ['signal', 'detail', 'weight'])
                || !is_string($reason['signal'] ?? null) || !is_string($reason['detail'] ?? null)
                || (!is_int($reason['weight'] ?? null) && !is_float($reason['weight'] ?? null))
                || !is_finite((float) $reason['weight'])) {
                return false;
            }
        }

        return true;
    }

    private function validContentAudit(mixed $audit): bool
    {
        if (!is_array($audit)
            || !$this->hasOnlyKeys($audit, ['score', 'grade', 'summary', 'counts', 'totalIssues', 'criticalCount', 'warningCount', 'suggestionCount', 'issues', 'goodPractices', 'homoglyphTerms', 'inputTruncated'])
            || !$this->nonNegativeInteger($audit['score'] ?? null, 100)
            || !in_array($audit['grade'] ?? null, ['A', 'B', 'C', 'D', 'F'], true)
            || !in_array($audit['summary'] ?? null, ['fix_critical', 'fix_warnings', 'review_suggestions', 'looks_good'], true)
            || !$this->validAuditCounts($audit['counts'] ?? null)
            || !$this->nonNegativeInteger($audit['totalIssues'] ?? null)
            || !$this->nonNegativeInteger($audit['criticalCount'] ?? null)
            || !$this->nonNegativeInteger($audit['warningCount'] ?? null)
            || !$this->nonNegativeInteger($audit['suggestionCount'] ?? null)
            || !$this->validAuditIssues($audit['issues'] ?? null)
            || !$this->validGoodPractices($audit['goodPractices'] ?? null)
            || (array_key_exists('homoglyphTerms', $audit) && !$this->stringList($audit['homoglyphTerms'], 20, 120))
            || !is_bool($audit['inputTruncated'] ?? null)) {
            return false;
        }

        return true;
    }

    private function validAuditCounts(mixed $counts): bool
    {
        if (!is_array($counts) || !$this->hasOnlyKeys($counts, ['words', 'links', 'images', 'triggerPhrases'])) {
            return false;
        }
        foreach (['words', 'links', 'images', 'triggerPhrases'] as $key) {
            if (!$this->nonNegativeInteger($counts[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function validAuditIssues(mixed $issues): bool
    {
        if (!is_array($issues) || !array_is_list($issues) || count($issues) > 50) {
            return false;
        }
        foreach ($issues as $issue) {
            if (!is_array($issue) || !$this->hasOnlyKeys($issue, ['code', 'category', 'severity', 'deduction', 'evidence'])
                || !is_string($issue['code'] ?? null)
                || !in_array($issue['category'] ?? null, ['subject', 'content', 'links', 'structure', 'compliance'], true)
                || !in_array($issue['severity'] ?? null, ['critical', 'warning', 'suggestion'], true)
                || !$this->nonNegativeInteger($issue['deduction'] ?? null, 100)
                || !is_string($issue['evidence'] ?? null) || mb_strlen($issue['evidence'], 'UTF-8') > 200) {
                return false;
            }
        }

        return true;
    }

    private function validGoodPractices(mixed $practices): bool
    {
        if (!is_array($practices) || !array_is_list($practices) || count($practices) > 20) {
            return false;
        }
        foreach ($practices as $practice) {
            if (!is_array($practice) || !$this->hasOnlyKeys($practice, ['code', 'category'])
                || !is_string($practice['code'] ?? null)
                || !in_array($practice['category'] ?? null, ['subject', 'content', 'links', 'structure', 'compliance'], true)) {
                return false;
            }
        }

        return true;
    }
}