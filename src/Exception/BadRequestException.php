<?php

declare(strict_types=1);

namespace Cbox\Billing\Exception;

/** 400: a malformed request, such as a missing `Idempotency-Key` or a bad cursor. */
class BadRequestException extends CboxBillingException {}
