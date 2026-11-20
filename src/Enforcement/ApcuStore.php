<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

use APCUIterator;
use RuntimeException;

/**
 * State in APCu: shared by every PHP-FPM worker on one host, gone on a restart of PHP-FPM. Right
 * for one host. Several hosts that report the same meters must share a {@see RedisStore}, because
 * Billing accepts one writer per (organization, meter) usage stream.
 */
final class ApcuStore implements Store
{
    public function __construct()
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            throw new RuntimeException('ApcuStore needs the apcu extension, enabled (apc.enable_cli=1 on the command line).');
        }
    }

    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key): ?string
    {
        $value = apcu_fetch($key, $found);

        return $found ? (string) $value : null;
    }

    public function increment(string $key, int $by): int
    {
        // apcu_inc keeps the entry's TTL; a missing key is created at 0 first.
        apcu_add($key, 0);
        $value = apcu_inc($key, $by, $success);
        if ($success !== true || $value === false) {
            throw new RuntimeException("APCu could not increment [{$key}].");
        }

        return (int) $value;
    }

    public function add(string $key, string $value, ?float $ttl = null): bool
    {
        return apcu_add($key, self::value($value), self::ttl($ttl)) === true;
    }

    public function put(string $key, string $value, ?float $ttl = null): void
    {
        apcu_store($key, self::value($value), self::ttl($ttl));
    }

    public function delete(string $key): bool
    {
        return apcu_delete($key) === true;
    }

    public function keys(string $prefix): array
    {
        $keys = [];
        foreach (new APCUIterator('/^'.preg_quote($prefix, '/').'/', APC_ITER_KEY) as $entry) {
            $keys[] = (string) $entry['key'];
        }
        sort($keys);

        return $keys;
    }

    /** apcu_inc only counts integers, so an integer written as text is kept as one. */
    private static function value(string $value): int|string
    {
        return preg_match('/^-?(0|[1-9][0-9]{0,17})$/', $value) === 1 ? (int) $value : $value;
    }

    /** APCu counts whole seconds; anything shorter lives one second. */
    private static function ttl(?float $ttl): int
    {
        return $ttl === null ? 0 : max(1, (int) ceil($ttl));
    }
}
