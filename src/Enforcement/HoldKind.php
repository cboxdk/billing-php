<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/** Where a hold's units came from. */
enum HoldKind: string
{
    /** The local leased slice. */
    case Lease = 'lease';
    /** In an outage: the last known allowance. */
    case Admitted = 'admitted';
    /** In an outage: the outage grace. */
    case Grace = 'grace';
    /** In an outage: the allow policy. */
    case Allowed = 'allowed';
}
