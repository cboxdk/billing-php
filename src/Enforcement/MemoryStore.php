<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

use Closure;
use UnexpectedValueException;

/**
 * State in this PHP process's memory. Right only for a long-running process (a queue worker,
 * Octane, RoadRunner, Swoole, a CLI script): under PHP-FPM every request is a new process, so use
 * an {@see ApcuStore} (one host) or a {@see RedisStore} (several hosts) there.
 */
final class MemoryStore implements Store
{
    /** @var array<string, array{0: string, 1: float|null}> */
    private array $entries = [];

    private readonly Closure $clock;

    /** @param  (Closure(): int)|null  $clock  milliseconds since the epoch; for tests */
    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    public function get(string $key): ?string
    {
        return $this->live($key)[0] ?? null;
    }

    public function increment(string $key, int $by): int
    {
        $entry = $this->live($key);
        if ($entry !== null && ! preg_match('/^-?\d+$/', $entry[0])) {
            throw new UnexpectedValueException("The value at [{$key}] is not an integer.");
        }
        $next = (int) ($entry[0] ?? 0) + $by;
        $this->entries[$key] = [(string) $next, $entry[1] ?? null];

        return $next;
    }

    public function add(string $key, string $value, ?float $ttl = null): bool
    {
        if ($this->live($key) !== null) {
            return false;
        }
        $this->entries[$key] = [$value, $this->expiry($ttl)];

        return true;
    }

    public function put(string $key, string $value, ?float $ttl = null): void
    {
        $this->entries[$key] = [$value, $this->expiry($ttl)];
    }

    public function delete(string $key): bool
    {
        $existed = $this->live($key) !== null;
        unset($this->entries[$key]);

        return $existed;
    }

    public function keys(string $prefix): array
    {
        $keys = [];
        foreach (array_keys($this->entries) as $key) {
            $key = (string) $key;
            if (str_starts_with($key, $prefix) && $this->live($key) !== null) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return $keys;
    }

    /** @return array{0: string, 1: float|null}|null */
    private function live(string $key): ?array
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry !== null && $entry[1] !== null && $entry[1] <= ($this->clock)()) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry;
    }

    private function expiry(?float $ttl): ?float
    {
        return $ttl === null ? null : ($this->clock)() + $ttl * 1000;
    }
}
