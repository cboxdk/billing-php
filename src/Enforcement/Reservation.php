<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/** Units held on one meter. Settle it with commit() or release(). */
final class Reservation
{
    public function __construct(
        public readonly string $id,
        public readonly string $org,
        public readonly Hold $hold,
    ) {}

    public function meter(): string
    {
        return $this->hold->meter;
    }

    public function amount(): int
    {
        return $this->hold->amount;
    }

    public function backedByLease(): bool
    {
        return $this->hold->backedByLease();
    }

    public function grace(): bool
    {
        return $this->hold->grace();
    }
}
