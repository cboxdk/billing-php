<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

use Closure;
use GuzzleHttp\Client;
use Throwable;

/**
 * The enforcement client's own HTTP layer. It never retries: a lease that fails goes to the failure
 * policy, and a report that fails is sent again by the next report. Answers are read strictly; a
 * body that is not a JSON object is a {@see BillingUnavailableException}.
 *
 * @internal
 */
final class Transport
{
    /**
     * @param  Closure(string, string, array<string, string>, ?string, float): array{0: int, 1: string}  $send
     * @param  array<string, string>  $headers
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly Closure $send,
        private readonly array $headers,
        private readonly float $timeout,
    ) {}

    /** The default HTTP function: Guzzle, without redirects or retries. */
    public static function guzzle(?Client $client = null): Closure
    {
        $client ??= new Client;

        return static function (string $method, string $url, array $headers, ?string $body, float $timeout) use ($client): array {
            $response = $client->request($method, $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $timeout,
                'connect_timeout' => min(2.0, $timeout),
                'allow_redirects' => false,
                'http_errors' => false,
            ]);

            return [$response->getStatusCode(), (string) $response->getBody()];
        };
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->send('GET', $path, null, $this->timeout);
    }

    /** @return array<string, mixed> */
    public function post(string $path, mixed $body, ?float $timeout = null): array
    {
        return $this->send('POST', $path, $body, $timeout ?? $this->timeout);
    }

    /** @return array<string, mixed> */
    private function send(string $method, string $path, mixed $body, float $timeout): array
    {
        $endpoint = explode('?', $path, 2)[0];
        $headers = $this->headers + [];
        $headers['Accept'] = 'application/json';
        $headers['Authorization'] = 'Bearer '.$this->token;
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        try {
            [$status, $text] = ($this->send)($method, $this->baseUrl.$path, $headers, $payload, $timeout);
        } catch (Throwable $e) {
            throw new BillingUnavailableException("Billing [{$endpoint}] is unreachable: {$e->getMessage()}.", $endpoint, null, $e);
        }

        $decoded = json_decode($text, true);
        $parsed = json_last_error() === JSON_ERROR_NONE;
        if ($status < 200 || $status > 299) {
            if ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
                throw new BillingRefusedException($endpoint, $status, $parsed ? $decoded : $text);
            }
            throw new BillingUnavailableException("Billing [{$endpoint}] answered HTTP {$status}.", $endpoint, $status);
        }
        // A JSON object decodes to an array that is not a list (or is empty, from `{}`).
        if (! $parsed || ! is_array($decoded) || (array_is_list($decoded) && $decoded !== []) || ! str_starts_with(ltrim($text), '{')) {
            throw self::malformed($endpoint, 'expected a JSON object');
        }

        return $decoded;
    }

    public static function malformed(string $endpoint, string $detail): BillingUnavailableException
    {
        return new BillingUnavailableException("Billing [{$endpoint}] gave a malformed answer: {$detail}.", $endpoint);
    }
}
