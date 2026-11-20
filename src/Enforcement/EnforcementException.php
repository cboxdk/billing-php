<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

use RuntimeException;

/**
 * Why the enforcement client could not hold units (see sdks/conformance/SPEC.md). An exhausted
 * allowance and a refusal are decisions and always refuse; only an unreachable Billing is resolved
 * by the failure policy. A bad argument is a plain \InvalidArgumentException.
 */
class EnforcementException extends RuntimeException {}
