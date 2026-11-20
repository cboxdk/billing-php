<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/**
 * Billing granted nothing (or, in an outage, the last known allowance is spent). Always refused.
 *
 * `$reason` is `quota_exhausted`, the lease's reason (`credit_limit_reached`, `overage_cap_reached`,
 * `seat_required`), `agreement_required`, or in an outage `outage_limit` or `meter_disabled`.
 */
final class QuotaExceededException extends EnforcementException
{
    public function __construct(
        public readonly string $org,
        public readonly string $meter,
        public readonly int $requested,
        public readonly string $reason,
    ) {
        parent::__construct("Allowance exhausted for meter [{$meter}] on org [{$org}]: cannot reserve {$requested} unit(s) ({$reason}).");
    }
}
