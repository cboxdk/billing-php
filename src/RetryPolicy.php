<?php

declare(strict_types=1);

namespace Cbox\Billing;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * How {@see CboxBilling} retries a 429, a 5xx or a network error: for reads, and for writes that
 * carry an `Idempotency-Key` only, so a retried write cannot apply twice.
 */
final class RetryPolicy
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param  int  $maxAttempts  Tries in all, the first included. 1 turns retries off.
     * @param  float  $baseDelay  The backoff before the first retry in seconds, doubled for each one after.
     * @param  float  $maxDelay  The longest wait between tries in seconds, `Retry-After` included.
     */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly float $baseDelay = 0.5,
        public readonly float $maxDelay = 20.0,
    ) {}

    public static function off(): self
    {
        return new self(maxAttempts: 1);
    }

    /** 429 and every 5xx are worth another try; anything else is the answer. */
    public static function isRetryableStatus(int $status): bool
    {
        return $status === 429 || ($status >= 500 && $status <= 599);
    }

    /**
     * The wait in seconds before retry number `$retry` (1 for the first): the server's
     * `Retry-After` when it sent one, otherwise exponential backoff with full jitter. Both are
     * capped at `maxDelay`.
     */
    public function delay(int $retry, ?string $retryAfter = null): float
    {
        $seconds = self::retryAfterSeconds($retryAfter);
        if ($seconds !== null) {
            return min(max($seconds, 0.0), $this->maxDelay);
        }

        $ceiling = min($this->maxDelay, $this->baseDelay * 2 ** ($retry - 1));

        return $ceiling * (mt_rand() / mt_getrandmax());
    }

    /** The Guzzle middleware that applies this policy. */
    public function middleware(): callable
    {
        return Middleware::retry(
            function (int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?Throwable $reason = null): bool {
                if ($retries + 1 >= $this->maxAttempts) {
                    return false;
                }
                if (! in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true) && ! $request->hasHeader('Idempotency-Key')) {
                    return false;
                }
                if ($response !== null) {
                    return self::isRetryableStatus($response->getStatusCode());
                }

                return $reason instanceof ConnectException;
            },
            fn (int $retries, ?ResponseInterface $response = null): int => (int) round(
                1000 * $this->delay($retries, $response?->getHeaderLine('Retry-After')),
            ),
        );
    }

    private static function retryAfterSeconds(?string $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $at = strtotime($value);

        return $at === false ? null : (float) ($at - time());
    }
}
