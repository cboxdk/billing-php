<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests;

use Cbox\Billing\Enforcement\BillingRefusedException;
use Cbox\Billing\Enforcement\BillingUnavailableException;
use Cbox\Billing\Enforcement\EnforcementClient;
use Cbox\Billing\Enforcement\FailurePolicy;
use Cbox\Billing\Enforcement\Hold;
use Cbox\Billing\Enforcement\MemoryStore;
use Cbox\Billing\Enforcement\QuotaExceededException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Runs the shared enforcement scenarios in sdks/conformance/scenarios against this SDK's
 * EnforcementClient, with a scripted Billing and a clock that moves only when a step says so.
 * sdks/conformance/README.md describes the format; every SDK runs the same files.
 */
final class ConformanceTest extends TestCase
{
    private const START = 1_800_000_000_000;

    private const BASE = 'http://billing.test/v1';

    private const OPTIONS = [
        'lease_size' => 'leaseSize', 'refill_threshold' => 'refillThreshold', 'failure_policy' => 'failurePolicy',
        'reservation_ttl' => 'reservationTtl', 'grace_units' => 'graceUnits', 'grace_window' => 'graceWindow',
        'grace_meters' => 'graceMeters', 'breaker_cooldown' => 'breakerCooldown', 'snapshot_refresh' => 'snapshotRefresh',
        'entitlements_ttl' => 'entitlementsTtl', 'lock_ttl' => 'lockTtl', 'lock_wait' => 'lockWait',
    ];

    private static function dir(): string
    {
        // sdks/conformance in this repository; conformance/ beside the SDK in the release mirror.
        foreach ([getenv('CBOX_CONFORMANCE_DIR'), __DIR__.'/../../conformance/scenarios', __DIR__.'/../conformance/scenarios'] as $dir) {
            if (is_string($dir) && $dir !== '' && is_dir($dir)) {
                return $dir;
            }
        }

        return __DIR__.'/../../conformance/scenarios';
    }

    /** @return array<string, array{string}> */
    public static function scenarios(): array
    {
        $files = glob(self::dir().'/*.json') ?: [];
        sort($files);
        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    public function test_the_conformance_scenarios_are_there(): void
    {
        $this->assertNotEmpty(self::scenarios(), 'No scenarios in '.self::dir().'.');
    }

    #[DataProvider('scenarios')]
    public function test_conformance(string $file): void
    {
        $scenario = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $now = self::START;
        $clock = static function () use (&$now): int {
            return $now;
        };

        // The scripted Billing.
        $queues = [];
        $always = [];
        foreach ($scenario['billing'] ?? [] as $route => $answer) {
            if (array_is_list($answer)) {
                $queues[$route] = $answer;
            } else {
                $always[$route] = $answer['always'];
            }
        }
        $received = [];
        $send = static function (string $method, string $url, array $headers, ?string $body) use (&$queues, $always, &$received): array {
            $route = $method.' '.substr((string) parse_url($url, PHP_URL_PATH), strlen('/v1'));
            $received[] = [$route, $body === null ? null : json_decode($body, true)];
            if (($queues[$route] ?? []) !== []) {
                $answer = array_shift($queues[$route]);
            } elseif (isset($always[$route])) {
                $answer = $always[$route];
            } else {
                throw new \LogicException("Billing has no answer left for {$route}.");
            }
            if (($answer['network_error'] ?? false) === true) {
                throw new RuntimeException('connection refused');
            }
            $raw = $answer['body'] ?? null;

            return [$answer['status'] ?? 200, is_string($raw) ? $raw : json_encode($raw, JSON_THROW_ON_ERROR)];
        };

        $args = [
            'token' => 'cbt_conformance',
            'baseUrl' => self::BASE,
            'store' => new MemoryStore($clock),
            'deferRefill' => false,
            'send' => $send,
            'clock' => $clock,
        ];
        foreach ($scenario['config'] ?? [] as $name => $value) {
            $this->assertArrayHasKey($name, self::OPTIONS, "Unknown config {$name}.");
            $args[self::OPTIONS[$name]] = $name === 'failure_policy' ? FailurePolicy::from($value) : $value;
        }
        $client = new EnforcementClient(...$args);
        $saved = [];

        foreach ($scenario['steps'] as $index => $step) {
            $where = sprintf('%s step %d (%s)', basename($file), $index + 1, $step['op']);
            $expect = $step['expect'] ?? [];
            $org = $step['org'] ?? null;

            if ($step['op'] === 'expect_requests') {
                $got = $received;
                $received = [];
                $this->assertSame(array_column($step['requests'], 'route'), array_column($got, 0), "{$where}: requests Billing received");
                foreach ($step['requests'] as $i => $request) {
                    if (array_key_exists('body', $request)) {
                        $this->assertEquals($request['body'], $got[$i][1], "{$where}: body of request ".($i + 1));
                    }
                }

                continue;
            }

            try {
                $result = match ($step['op']) {
                    'reserve' => (function () use ($client, $org, $step, &$saved) {
                        $r = $client->reserve($org, $step['meter'], $step['estimate']);
                        if (isset($step['save'])) {
                            $saved[$step['save']] = $r;
                        }

                        return self::holdView($r->hold);
                    })(),
                    'reserve_many' => (function () use ($client, $org, $step, &$saved) {
                        $set = $client->reserveMany($org, $step['meters']);
                        if (isset($step['save'])) {
                            $saved[$step['save']] = $set;
                        }
                        $buckets = [];
                        foreach ($set->holds as $hold) {
                            $buckets[$hold->meter] = self::holdView($hold);
                        }

                        return ['buckets' => $buckets];
                    })(),
                    'commit' => $client->commit($saved[$step['ref']], $step['actual']),
                    'release' => $client->release($saved[$step['ref']]),
                    'can' => $client->can($org, $step['meter'], $step['n']),
                    'balance' => $client->balance($org, $step['meter']),
                    'report' => $client->report($org),
                    'sweep' => $client->sweep(),
                    'entitlements' => (function () use ($client, $org) {
                        $e = $client->entitlements($org);

                        return ['stale' => $e->stale, 'meters' => array_map(static fn ($m) => $m->toArray(), $e->meters)];
                    })(),
                    'features' => (function () use ($client, $org) {
                        $f = $client->features($org);

                        return ['stale' => $f->stale, 'suspended' => $f->suspended, 'features' => array_map(static fn ($d) => $d->toArray(), $f->features)];
                    })(),
                    'has_feature' => $client->hasFeature($org, $step['key']),
                    'feature_value' => $client->featureValue($org, $step['key']),
                    'cost' => $client->cost($org, $step['meter'], $step['units']),
                    'invalidate' => $client->invalidate($org),
                    'handle_webhook' => $client->handleWebhook($step['event']),
                    'advance' => (function () use (&$now, $step) {
                        $now += (int) ($step['seconds'] * 1000);
                    })(),
                    'respond' => (function () use (&$queues, $step) {
                        array_push($queues[$step['route']], ...$step['with']);
                    })(),
                    default => throw new \LogicException("Unknown op {$step['op']}."),
                };
            } catch (\LogicException $e) {
                if ($e instanceof InvalidArgumentException) {
                    $this->checkError($where, $expect, $e);

                    continue;
                }
                throw $e;
            } catch (Throwable $e) {
                $this->checkError($where, $expect, $e);

                continue;
            }

            $this->assertArrayNotHasKey('error', $expect, "{$where} should have thrown ".($expect['error'] ?? ''));
            if (array_key_exists('result', $expect)) {
                $this->assertEquals(
                    self::normalize($expect['result']),
                    self::normalize($result),
                    "{$where}: result",
                );
            }
        }

        $left = array_keys(array_filter($queues, static fn (array $q): bool => $q !== []));
        $this->assertSame([], $left, 'scripted answers left unused');
    }

    /** @param  array<string, mixed>  $expect */
    private function checkError(string $where, array $expect, Throwable $e): void
    {
        $this->assertArrayHasKey('error', $expect, "{$where} threw ".$e::class.': '.$e->getMessage());
        $name = match (true) {
            $e instanceof QuotaExceededException => 'QuotaExceeded',
            $e instanceof BillingRefusedException => 'BillingRefused',
            $e instanceof BillingUnavailableException => 'BillingUnavailable',
            $e instanceof InvalidArgumentException => 'InvalidArgument',
            default => 'unexpected '.$e::class.': '.$e->getMessage(),
        };
        $this->assertSame($expect['error'], $name, "{$where}: error");
        if (isset($expect['reason'])) {
            $this->assertInstanceOf(QuotaExceededException::class, $e);
            $this->assertSame($expect['reason'], $e->reason, "{$where}: reason");
        }
        if (isset($expect['status'])) {
            $this->assertInstanceOf(BillingRefusedException::class, $e);
            $this->assertSame($expect['status'], $e->status, "{$where}: status");
        }
    }

    /** @return array{backed_by_lease: bool, grace: bool} */
    private static function holdView(Hold $hold): array
    {
        return ['backed_by_lease' => $hold->backedByLease(), 'grace' => $hold->grace()];
    }

    /** Compare as JSON: numbers by value (5 equals 5.0), objects by their keys. */
    private static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value);

            return array_map(self::normalize(...), $value);
        }

        return is_int($value) || is_float($value) ? (float) $value : $value;
    }
}
