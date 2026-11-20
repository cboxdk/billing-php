<?php

declare(strict_types=1);

namespace Cbox\Billing\Exception;

/** 409: a state conflict, or an `Idempotency-Key` reused with another body or still in flight. */
class ConflictException extends CboxBillingException {}
