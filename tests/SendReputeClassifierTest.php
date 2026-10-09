<?php

declare(strict_types=1);

namespace SendRepute\Symfony\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SendRepute\Symfony\Exception\SendReputeException;
use SendRepute\Symfony\SendReputeClassifier;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SendReputeClassifierTest extends TestCase
{
    public function testPublicContractAndFixedEndpoint(): void
    {
        $fixture = file_get_contents(__DIR__.'/fixtures/classification-success.json');
        self::assertIsString($fixture);
        $seen = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($fixture, &$seen): MockResponse {
            $seen = [$method, $url, $options];

            return new MockResponse($fixture, [
                'http_code' => 200,
                'response_headers' => ['content-type: application/json'],
            ]);
        });

        $result = (new SendReputeClassifier($http, 'server-secret', true))
            ->classify('Display Name', 'Subject', 'Visible body');

        self::assertSame('req_fixture_1', $result->requestId);
        self::assertSame(0.91, $result->spamProbability);
        self::assertSame(['POST', SendReputeClassifier::ENDPOINT], array_slice($seen, 0, 2));
        self::assertSame(
            ['sender' => 'Display Name', 'subject' => 'Subject', 'body' => 'Visible body'],
            json_decode($seen[2]['body'], true, 32, JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, $seen[2]['max_redirects']);
        self::assertStringContainsString('Bearer server-secret', implode(' ', $seen[2]['normalized_headers']['authorization']));
    }

    public function testPaidConsentDefaultsToDenied(): void
    {
        $http = new MockHttpClient(static fn (): never => throw new \LogicException('must not call'));
        $this->expectException(SendReputeException::class);
        $this->expectExceptionMessage('explicitly enabled');

        (new SendReputeClassifier($http, 'token', false))->classify('Sender', 'Subject', 'Body');
    }

    public function testNestedResponseContractIsRequired(): void
    {
        $http = new MockHttpClient(new MockResponse('{"requestId":"x","result":{"spamProbability":0.1}}', ['http_code' => 200]));
        $this->expectException(SendReputeException::class);
        $this->expectExceptionMessage('invalid classification result');

        (new SendReputeClassifier($http, 'token', true))->classify('Sender', 'Subject', 'Body');
    }

    #[DataProvider('invalidRequiredResponseFields')]
    public function testEveryRequiredResponseFieldIsTyped(array $path, mixed $invalid): void
    {
        $value = json_decode((string) file_get_contents(__DIR__.'/fixtures/classification-success.json'), true, 32, JSON_THROW_ON_ERROR);
        $cursor = &$value;
        foreach (array_slice($path, 0, -1) as $segment) {
            $cursor = &$cursor[$segment];
        }
        $cursor[$path[array_key_last($path)]] = $invalid;
        $http = new MockHttpClient(new MockResponse(json_encode($value, JSON_THROW_ON_ERROR), ['http_code' => 200]));
        $this->expectException(SendReputeException::class);
        $this->expectExceptionMessage('invalid classification result');

        (new SendReputeClassifier($http, 'token', true))->classify('Sender', 'Subject', 'Body');
    }

    public static function invalidRequiredResponseFields(): iterable
    {
        yield 'requestId' => [['requestId'], 4];
        yield 'model enum' => [['model'], 'thor-lookalike'];
        yield 'label enum' => [['result', 'label'], 'maybe'];
        yield 'spamProbability' => [['result', 'spamProbability'], '0.5'];
        yield 'confidence enum' => [['result', 'confidence'], 'certain'];
        yield 'reasons list' => [['result', 'reasons'], ['not-an-object']];
        yield 'reason signal' => [['result', 'reasons', 0, 'signal'], 3];
        yield 'reason detail' => [['result', 'reasons', 0, 'detail'], false];
        yield 'reason weight' => [['result', 'reasons', 0, 'weight'], 'heavy'];
        yield 'flaggedTerms' => [['result', 'flaggedTerms'], [4]];
        yield 'analyzedFields' => [['result', 'analyzedFields'], [null]];
        yield 'modelVersion' => [['result', 'modelVersion'], 2];
        yield 'analyzedAt' => [['result', 'analyzedAt'], []];
        yield 'billing replayed' => [['billing', 'replayed'], 0];
        yield 'billing charge' => [['billing', 'chargedMillicents'], 0.5];
    }

    public function testRedirectIsNotFollowed(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 302, 'response_headers' => ['location: https://evil.test']]));
        $this->expectException(SendReputeException::class);
        $this->expectExceptionMessage('redirects are refused');

        (new SendReputeClassifier($http, 'token', true))->classify('Sender', 'Subject', 'Body');
    }

    public function testResponseOverOneMibIsRejected(): void
    {
        $http = new MockHttpClient(new MockResponse(str_repeat('x', 1_048_577), ['http_code' => 200]));
        $this->expectException(SendReputeException::class);
        $this->expectExceptionMessage('exceeded 1 MiB');

        (new SendReputeClassifier($http, 'token', true))->classify('Sender', 'Subject', 'Body');
    }
}