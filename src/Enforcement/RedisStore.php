<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

use Closure;
use UnexpectedValueException;

/**
 * State in Redis, shared by every process and host that uses the same Redis and prefix. Billing
 * accepts one writer per (organization, meter) usage stream, so processes that report the same
 * meters must share one store.
 *
 * It runs raw commands through one closure, so it works with any client:
 *
 *     new RedisStore(fn (array $args) => $phpredis->rawCommand(...$args));   // or RedisStore::phpredis($redis)
 *     new RedisStore(fn (array $args) => $predis->executeRaw($args));        // or RedisStore::predis($client)
 *
 * The closure returns the reply, with null for a nil reply.
 */
final class RedisStore implements Store
{
    /** @param  Closure(list<string>): mixed  $command */
    public function __construct(private readonly Closure $command) {}

    /** With the phpredis extension's \Redis. */
    public static function phpredis(object $redis): self
    {
        return new self(static function (array $args) use ($redis): mixed {
            $reply = $redis->rawCommand(...$args);

            return $reply === false && $args[0] === 'GET' ? null : $reply;
        });
    }

    /** With a Predis\Client. */
    public static function predis(object $client): self
    {
        return new self(static fn (array $args): mixed => $client->executeRaw($args));
    }

    public function get(string $key): ?string
    {
        $reply = ($this->command)(['GET', $key]);

        return $reply === null || $reply === false ? null : (string) $reply;
    }

    public function increment(string $key, int $by): int
    {
        $reply = ($this->command)(['INCRBY', $key, (string) $by]);
        if (! is_numeric($reply)) {
            throw new UnexpectedValueException("Redis INCRBY on [{$key}] answered ".get_debug_type($reply).'.');
        }

        return (int) $reply;
    }

    public function add(string $key, string $value, ?float $ttl = null): bool
    {
        $reply = ($this->command)(['SET', $key, $value, 'NX', ...self::expiry($ttl)]);

        return $reply !== null && $reply !== false;
    }

    public function put(string $key, string $value, ?float $ttl = null): void
    {
        ($this->command)(['SET', $key, $value, ...self::expiry($ttl)]);
    }

    public function delete(string $key): bool
    {
        return (int) ($this->command)(['DEL', $key]) > 0;
    }

    public function keys(string $prefix): array
    {
        $pattern = preg_replace('/([\\\\*?\[\]])/', '\\\\$1', $prefix).'*';
        $found = [];
        $cursor = '0';
        do {
            $reply = ($this->command)(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '500']);
            if (! is_array($reply) || count($reply) !== 2) {
                throw new UnexpectedValueException('Redis SCAN gave an unexpected reply.');
            }
            $cursor = (string) $reply[0];
            foreach ((array) $reply[1] as $key) {
                $found[(string) $key] = true;
            }
        } while ($cursor !== '0');
        $keys = array_map('strval', array_keys($found));
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    private static function expiry(?float $ttl): array
    {
        return $ttl === null ? [] : ['PX', (string) max(1, (int) round($ttl * 1000))];
    }
}
