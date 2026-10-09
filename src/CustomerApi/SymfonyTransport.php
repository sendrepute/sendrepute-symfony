<?php

declare(strict_types=1);

namespace SendRepute\Symfony\CustomerApi;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SymfonyTransport implements Transport
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, int $maxBytes): array
    {
        try {
            $options = ['headers' => $headers, 'max_redirects' => 0, 'timeout' => $timeout, 'max_duration' => $timeout, 'verify_peer' => true, 'verify_host' => true];
            if ($body !== null) {
                $options['body'] = $body;
            }
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $responseHeaders = $response->getHeaders(false);
            $raw = '';
            foreach ($this->http->stream($response, $timeout) as $chunk) {
                if ($chunk->isTimeout()) {
                    $response->cancel();
                    throw new CustomerApiException('transport', 'Response body timed out. It was not retried.', $status);
                }
                $raw .= $chunk->getContent();
                if (strlen($raw) > $maxBytes) {
                    $response->cancel();
                    throw new CustomerApiException('response_too_large', 'Response exceeds the configured size limit.', $status);
                }
            }
        } catch (ExceptionInterface) {
            throw new CustomerApiException('transport', 'SendRepute request failed before a response was received. It was not retried.');
        }
        $out = [];
        foreach ($responseHeaders as $name => $values) {
            $out[$name] = implode(', ', $values);
        }

        return ['status' => $status, 'headers' => $out, 'body' => $raw];
    }
}
