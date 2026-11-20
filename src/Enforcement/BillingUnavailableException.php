<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

use Throwable;

/** Billing could not be reached, or gave no usable answer. Resolved by the failure policy. */
final class BillingUnavailableException extends EnforcementException
{
    public function __construct(
        string $message,
        public readonly string $endpoint,
        /** Null when no response arrived. */
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }
}
