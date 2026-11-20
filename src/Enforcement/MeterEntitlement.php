<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/** One meter's entitlement, as `GET /entitlements/{org}` resolves it. */
final class MeterEntitlement
{
    public function __construct(
        public readonly bool $enabled,
        /** Included units per period; null when unlimited or disabled. */
        public readonly ?int $allowance,
        /** Billable cost per unit; null when disabled. */
        public readonly ?float $weight,
        /** `block` (a hard limit), `bill` (paid overage), or another behaviour Billing names. */
        public readonly string $overage,
    ) {}

    /** True when usage must stop at the allowance: enabled, not unlimited, not billed overage. */
    public function capped(): bool
    {
        return $this->enabled && $this->allowance !== null && $this->overage !== 'bill';
    }

    /** @return array{enabled: bool, allowance: int|null, weight: float|int|null, overage: string} */
    public function toArray(): array
    {
        return ['enabled' => $this->enabled, 'allowance' => $this->allowance, 'weight' => $this->weight, 'overage' => $this->overage];
    }
}
