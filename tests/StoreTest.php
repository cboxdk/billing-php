<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests;

use Cbox\Billing\Enforcement\ApcuStore;
use Cbox\Billing\Enforcement\EnforcementClient;
use Cbox\Billing\Enforcement\MemoryStore;
use Cbox\Billing\Enforcement\QuotaExceededException;
use Cbox\Billing\Enforcement\RedisStore;
use Cbox\Billing\Enforcement\Store;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The store contract, run against the memory store, APCu (when the extension is enabled) and,
 * when REDIS_URL is set (such as redis://127.0.0.1:6379/15), a real Redis. Then forked workers
 * drain one leased slice through a shared Redis: exactly the leased units are committed, never
 * more.
 */
final class StoreTest extends TestCase
{
    private const START = 1_800_000_000_000;

    /** @return iterable<string, array{string}> */
    public static function stores(): iterable
    {
        yield 'memory' => ['memory'];
        yield 'apcu' => ['apcu'];
        yield 'redis' => ['redis'];
    }

    /** @return array{Store, Closure(int): void} the store, and a function that moves its time by ms */
    private function open(string $kind): array
    {
        switch ($kind) {
            case 'memory':
                $now = self::START;

                return [new MemoryStore(static function () use (&$now): int {
                    return $now;
                }), static function (int $ms) use (&$now): void {
                    $now += $ms;
                }];
            case 'apcu':
                if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
                    $this->markTestSkipped('APCu is not enabled (apc.enable_cli=1).');
                }

                return [new ApcuStore, static fn (int $ms) => usleep(($ms + 1000) * 1000)];
            default:
                return [new RedisStore(self::redis()), static fn (int $ms) => usleep(($ms + 50) * 1000)];
        }
    }

    #[DataProvider('stores')]
    public function test_the_store_contract(string $kind): void
    {
        [$s, $advance] = $this->open($kind);
        $p = self::prefix('contract');

        $this->assertNull($s->get($p.'missing'));
        $this->assertSame(5, $s->increment($p.'n', 5));
        $this->assertSame(-2, $s->increment($p.'n', -7));
        $this->assertTrue($s->add($p.'a', 'first'));
        $this->assertFalse($s->add($p.'a', 'second'));
        $this->assertSame('first', $s->get($p.'a'));
        $s->put($p.'a', 'third');
        $this->assertSame('third', $s->get($p.'a'));
        $this->assertTrue($s->delete($p.'a'));
        $this->assertFalse($s->delete($p.'a'), 'a second delete claimed the key again');

        // keys() matches the prefix literally, glob characters included.
        foreach (['k:a', 'k:b', 'k*:c', 'kx'] as $k) {
            $s->put($p.$k, '1');
        }
        $keys = $s->keys($p.'k:');
        sort($keys);
        $this->assertSame([$p.'k:a', $p.'k:b'], $keys);
        $this->assertSame([$p.'k*:c'], $s->keys($p.'k*'));

        // Expiry (APCu counts whole seconds), and increment keeps it.
        $ttl = $kind === 'apcu' ? 1.0 : 0.2;
        $s->put($p.'ttl', '4', $ttl);
        $this->assertSame(5, $s->increment($p.'ttl', 1));
        $advance((int) ($ttl * 1000) + 1);
        $this->assertNull($s->get($p.'ttl'), 'the key outlived its ttl; increment dropped the expiry');
        $this->assertTrue($s->add($p.'ttl', 'again'));
    }

    public function test_forked_workers_never_overdraw_a_shared_slice(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available.');
        }
        $granted = 300;
        $prefix = self::prefix('concurrent');
        $client = static function (int $grant) use ($prefix, $granted): EnforcementClient {
            return new EnforcementClient(
                token: 'cbt_test',
                baseUrl: 'http://billing.test/v1',
                store: new RedisStore(self::redis()),
                leaseSize: $granted,
                prefix: $prefix,
                deferRefill: false,
                send: static fn (string $method, string $url): array => str_ends_with($url, '/leases')
                    ? [200, json_encode(['lease_id' => 'lease', 'granted' => $grant, 'expires_at' => null])]
                    : [200, '{"meters":{"api":{"enabled":true,"allowance":null,"overage":"block"}}}'],
            );
        };

        // Lease the slice once; the workers' Billing grants nothing more.
        $first = $client($granted);
        $first->release($first->reserve('org_a', 'api', 1));
        $this->assertSame($granted, $first->balance('org_a', 'api'));

        $pids = [];
        for ($w = 0; $w < 8; $w++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                // The child reports through Redis and kills itself, so PHPUnit's shutdown never
                // runs twice.
                try {
                    $worker = $client(0);
                    $committed = 0;
                    for ($i = 0; ; $i++) {
                        // Every third request holds 3 and gives the whole hold back; the rest use 1.
                        $estimate = ($w + $i) % 3 === 0 ? 3 : 1;
                        try {
                            $r = $worker->reserve('org_a', 'api', $estimate);
                        } catch (QuotaExceededException) {
                            // A concurrent take can refuse for a moment; stop once the slice is empty.
                            if ($worker->balance('org_a', 'api') > 0) {
                                continue;
                            }
                            break;
                        }
                        if ($estimate === 3) {
                            $worker->release($r);
                        } else {
                            $worker->commit($r, 1);
                            $committed++;
                        }
                    }
                    (new RedisStore(self::redis()))->increment($prefix.'test-committed', $committed);
                } catch (\Throwable $e) {
                    (new RedisStore(self::redis()))->put($prefix.'test-error', $e::class.': '.$e->getMessage());
                }
                posix_kill(getmypid(), SIGKILL);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $store = new RedisStore(self::redis());
        $this->assertNull($store->get($prefix.'test-error'), 'a worker failed');
        $this->assertSame((string) $granted, $store->get($prefix.'test-committed'), 'committed more than the slice');
        $this->assertSame(0, $first->balance('org_a', 'api'));
        $this->assertSame([], $store->keys($prefix.'res:'), 'every reservation was settled');
        $this->assertSame((string) $granted, $store->get($prefix.'usage:org_a:api'));
    }

    private static function prefix(string $name): string
    {
        static $run = 0;

        return sprintf('cbox-billing-test:%s:%d:%d:%s:', $name, getmypid(), $run++, hrtime(true));
    }

    /** A minimal Redis client for the tests, so the SDK needs no Redis dependency. */
    private static function redis(): Closure
    {
        $url = getenv('REDIS_URL');
        if ($url === false || $url === '') {
            self::markTestSkipped('REDIS_URL is not set.');
        }
        $parts = parse_url($url);
        $socket = stream_socket_client(sprintf('tcp://%s:%d', $parts['host'] ?? '127.0.0.1', $parts['port'] ?? 6379), $errno, $error, 2);
        if ($socket === false) {
            throw new RuntimeException("Redis: {$error}");
        }
        $read = static function () use ($socket, &$read): mixed {
            $line = substr((string) fgets($socket), 0, -2);
            $rest = substr($line, 1);
            $n = (int) $rest;

            return match ($line[0]) {
                '+' => $rest,
                '-' => throw new RuntimeException($rest),
                ':' => $n,
                '$' => $n < 0 ? null : substr((string) stream_get_contents($socket, $n + 2), 0, -2),
                '*' => $n < 0 ? null : array_map(static fn () => $read(), array_fill(0, $n, null)),
                default => throw new RuntimeException("Redis: unexpected reply {$line}"),
            };
        };
        $command = static function (array $args) use ($socket, $read): mixed {
            $payload = '*'.count($args)."\r\n";
            foreach ($args as $arg) {
                $payload .= '$'.strlen((string) $arg)."\r\n{$arg}\r\n";
            }
            fwrite($socket, $payload);

            return $read();
        };
        $db = trim($parts['path'] ?? '', '/');
        if ($db !== '') {
            $command(['SELECT', $db]);
        }

        return $command;
    }
}
