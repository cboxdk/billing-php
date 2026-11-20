<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/** Units held on several meters at once. Settle it with commit() (an actual per meter) or release(). */
final class ReservationSet
{
    /** @param  list<Hold>  $holds */
    public function __construct(
        public readonly string $id,
        public readonly string $org,
        public readonly array $holds,
    ) {}

    /** @return array<string, Hold> the hold per meter */
    public function buckets(): array
    {
        $buckets = [];
        foreach ($this->holds as $hold) {
            $buckets[$hold->meter] = $hold;
        }

        return $buckets;
    }
}
