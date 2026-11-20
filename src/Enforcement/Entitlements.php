<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/** An organization's per-meter entitlements. Deny by default: an unknown meter is not enabled. */
final class Entitlements
{
    /** @param  array<string, MeterEntitlement>  $meters */
    public function __construct(
        public readonly string $org,
        public readonly array $meters,
        /** True when Billing could not be reached and this is the last answer it gave. */
        public readonly bool $stale,
    ) {}

    public function for(string $meter): ?MeterEntitlement
    {
        return $this->meters[$meter] ?? null;
    }

    public function enabled(string $meter): bool
    {
        return $this->for($meter)?->enabled === true;
    }

    /** `units × weight`; nothing for an unknown meter, a meter without a weight or no units. */
    public function cost(string $meter, int $units): float
    {
        $weight = $this->for($meter)?->weight;

        return $weight === null || $units <= 0 ? 0.0 : $units * $weight;
    }
}
