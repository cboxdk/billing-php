<?php

declare(strict_types=1);

namespace Cbox\Billing;

use Cbox\Billing\Exception\CboxBillingException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Sets up the generated API classes for Cbox Billing:
 *
 * - bearer auth with your token, against your base URL;
 * - every write (POST, PUT, PATCH, DELETE) without an `Idempotency-Key` gets a fresh UUIDv4 one,
 *   unless `$autoIdempotencyKey` is false;
 * - a 429, a 5xx or a network error is retried per {@see RetryPolicy} (3 tries by default;
 *   `RetryPolicy::off()` turns it off), honouring `Retry-After`, for reads and for writes that
 *   carry an `Idempotency-Key` (every try sends the same key);
 * - every non-2xx response throws a {@see CboxBillingException} subclass.
 *
 *     $billing = new CboxBilling(getenv('CBOX_BILLING_TOKEN'), 'https://api.cboxbilling.com/v1');
 *     $plans = $billing->api(PlansApi::class)->listPlans();
 */
final class CboxBilling
{
    public const VERSION = '0.2.1';

    private readonly Configuration $configuration;

    private readonly ClientInterface $client;

    /**
     * @param  string  $token  A Cbox Billing API token: `cbl_…` (live) or `cbt_…` (test).
     * @param  string  $baseUrl  Your deployment's API root, e.g. `https://api.cboxbilling.com/v1` (`/api/v1` on a self-hosted single-host deployment).
     * @param  array<string, string>  $headers  Headers sent on every request.
     * @param  HandlerStack|null  $handler  A Guzzle handler stack, e.g. with your own middleware.
     * @param  RetryPolicy|null  $retry  Defaults to `new RetryPolicy` (3 tries).
     * @param  bool  $autoIdempotencyKey  Give every write without an `Idempotency-Key` one.
     */
    public function __construct(
        string $token,
        string $baseUrl,
        array $headers = [],
        ?HandlerStack $handler = null,
        ?RetryPolicy $retry = null,
        bool $autoIdempotencyKey = true,
    ) {
        if ($token === '') {
            throw new InvalidArgumentException('A Cbox Billing API token is required.');
        }

        $this->configuration = (new Configuration)
            ->setHost(rtrim($baseUrl, '/'))
            ->setAccessToken($token)
            ->setUserAgent('cbox-billing-php/'.self::VERSION);

        $this->client = self::httpClient($headers, $handler, $retry, $autoIdempotencyKey);
    }

    /**
     * A Guzzle client with the automatic idempotency keys, the retries and the typed exceptions.
     * Pass it, with a Configuration, to any generated API class.
     *
     * @param  array<string, string>  $headers
     */
    public static function httpClient(
        array $headers = [],
        ?HandlerStack $handler = null,
        ?RetryPolicy $retry = null,
        bool $autoIdempotencyKey = true,
    ): ClientInterface {
        $stack = $handler ?? HandlerStack::create();
        // Guzzle runs the first-pushed middleware outermost. So the typed exception sees only the
        // final answer, the key is set once before the retries (every try sends it), and the
        // retries sit closest to the wire.
        $stack->push(Middleware::mapResponse(static function (ResponseInterface $response): ResponseInterface {
            if ($response->getStatusCode() >= 300) {
                throw CboxBillingException::fromResponse($response);
            }

            return $response;
        }), 'cbox_billing_typed_errors');
        if ($autoIdempotencyKey) {
            $stack->push(Middleware::mapRequest(static function (RequestInterface $request): RequestInterface {
                $write = in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);

                return $write && ! $request->hasHeader('Idempotency-Key')
                    ? $request->withHeader('Idempotency-Key', Idempotency::newKey())
                    : $request;
            }), 'cbox_billing_idempotency_key');
        }
        $stack->push(($retry ?? new RetryPolicy)->middleware(), 'cbox_billing_retry');

        return new Client([
            'handler' => $stack,
            'headers' => $headers,
            // The middleware above raises the typed exception instead.
            'http_errors' => false,
        ]);
    }

    /**
     * An instance of a generated API class, sharing this client and configuration.
     *
     * @template T of object
     *
     * @param  class-string<T>  $api
     * @return T
     */
    public function api(string $api): object
    {
        return new $api($this->client, $this->configuration);
    }

    public function configuration(): Configuration
    {
        return $this->configuration;
    }

    public function client(): ClientInterface
    {
        return $this->client;
    }
}
