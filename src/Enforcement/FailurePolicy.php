<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/** What the client does when Billing cannot be reached and the local slice is too small. */
enum FailurePolicy: string
{
    /** Admit up to the allowance last known, then a small outage grace. The default. */
    case LastKnown = 'last_known';
    /** Admit everything. */
    case Allow = 'allow';
    /** Refuse with a BillingUnavailableException. */
    case Deny = 'deny';
}
