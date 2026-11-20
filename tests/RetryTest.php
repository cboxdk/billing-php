<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests;

use Cbox\Billing\Api\SubscriptionsApi;
use Cbox\Billing\CboxBilling;
use Cbox\Billing\Exception\BadRequestException;
use Cbox\Billing\Exception\ServerException;
use Cbox\Billing\Idempotency;
use Cbox\Billing\Model\QuantityRequest;
use Cbox\Billing\RetryPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Retries and automatic idempotency keys, through the fault proxy in front of Prism
 * (sdks/testing/fault-proxy.mjs): it answers the first tries with an injected status and records
 * every attempt's Idempotency-Key.
 */
final class RetryTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private static function baseUrl(): string
    {
        $url = getenv('CBOX_BILLING_MOCK_URL');

        return is_string($url) && $url !== '' ? $url : 'http://127.0.0.1:4010';
    }

    /** @return array{0: string, 1: SubscriptionsApi} */
    private function faulty(int $times, int $status, ?string $retryAfter = null, ?RetryPolicy $retry = null, bool $autoKey = true): array
    {
        $id = Idempotency::newKey();
        $headers = ['X-Fault-Id' => $id, 'X-Fault-Times' => (string) $times, 'X-Fault-Status' => (string) $status];
        if ($retryAfter !== null) {
            $headers['X-Fault-Retry-After'] = $retryAfter;
        }
        $billing = new CboxBilling('cbt_test_token', self::baseUrl(), $headers, retry: $retry, autoIdempotencyKey: $autoKey);

        return [$id, $billing->api(SubscriptionsApi::class)];
    }

    /** @return list<array{method: string, path: string, idempotency_key: string|null}> */
    private function attempts(string $id): array
    {
        return json_decode((string) file_get_contents(self::baseUrl().'/__faults/'.$id), true, 512, JSON_THROW_ON_ERROR);
    }

    private function change(SubscriptionsApi $api, ?string $key = null): mixed
    {
        return $api->changeSubscriptionQuantity('org_acme', new QuantityRequest(['seats' => 10]), idempotencyKey: $key);
    }

    public function test_a_503_then_a_200_is_retried_honouring_retry_after_with_the_same_automatic_key(): void
    {
        [$id, $api] = $this->faulty(1, 503, retryAfter: '1');
        $started = microtime(true);

        $this->assertNotNull($this->change($api));

        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started);
        $tries = $this->attempts($id);
        $this->assertCount(2, $tries);
        $this->assertMatchesRegularExpression(self::UUID_V4, (string) $tries[0]['idempotency_key']);
        $this->assertSame($tries[0]['idempotency_key'], $tries[1]['idempotency_key']);
    }

    public function test_a_400_is_not_retried(): void
    {
        [$id, $api] = $this->faulty(1, 400);

        try {
            $this->change($api);
            $this->fail('Expected a 400.');
        } catch (BadRequestException) {
        }
        $this->assertCount(1, $this->attempts($id));
    }

    public function test_the_callers_own_key_is_kept_and_a_write_without_a_key_is_not_retried(): void
    {
        [$id, $api] = $this->faulty(1, 503, retryAfter: '0');
        $this->change($api, 'my-key-1');
        $this->assertSame(['my-key-1', 'my-key-1'], array_column($this->attempts($id), 'idempotency_key'));

        [$id, $api] = $this->faulty(1, 503, retryAfter: '0', autoKey: false);
        try {
            $this->change($api);
            $this->fail('Expected a 503.');
        } catch (ServerException) {
        }
        $tries = $this->attempts($id);
        $this->assertCount(1, $tries);
        $this->assertNull($tries[0]['idempotency_key']);
    }

    public function test_retries_stop_after_the_configured_tries_or_never_start_when_turned_off(): void
    {
        [$id, $api] = $this->faulty(5, 503, retryAfter: '0', retry: new RetryPolicy(maxAttempts: 2));
        try {
            $this->change($api);
            $this->fail('Expected a 503.');
        } catch (ServerException) {
        }
        $this->assertCount(2, $this->attempts($id));

        [$id, $api] = $this->faulty(1, 503, retryAfter: '0', retry: RetryPolicy::off());
        try {
            $this->change($api);
            $this->fail('Expected a 503.');
        } catch (ServerException) {
        }
        $this->assertCount(1, $this->attempts($id));
    }
}
