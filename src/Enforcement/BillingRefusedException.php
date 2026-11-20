<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/** Billing answered with a refusal (a 4xx other than 408 and 429). Always refused. */
final class BillingRefusedException extends EnforcementException
{
    public function __construct(
        public readonly string $endpoint,
        public readonly int $status,
        /** The response body, decoded when it was JSON. */
        public readonly mixed $body,
    ) {
        parent::__construct("Billing refused [{$endpoint}] with HTTP {$status}.", $status);
    }
}
