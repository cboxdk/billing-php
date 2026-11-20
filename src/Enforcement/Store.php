<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/**
 * Where the enforcement client keeps its state: leased slices, open reservations, the usage
 * buffer, the last known allowance, grace counters, the breaker, locks and the entitlement cache.
 *
 * `increment()` is atomic, starts a missing key at 0 and keeps the key's expiry. `add()` sets a key
 * only when it is absent. `delete()` says whether the key existed, so closing a reservation is an
 * atomic claim. A null TTL means no expiry; TTLs are in seconds.
 */
interface Store
{
    public function get(string $key): ?string;

    public function increment(string $key, int $by): int;

    public function add(string $key, string $value, ?float $ttl = null): bool;

    public function put(string $key, string $value, ?float $ttl = null): void;

    public function delete(string $key): bool;

    /** @return list<string> */
    public function keys(string $prefix): array;
}
