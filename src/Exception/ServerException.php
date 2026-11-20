<?php

declare(strict_types=1);

namespace Cbox\Billing\Exception;

/** 5xx: the server failed. Retry a write only with the same `Idempotency-Key`. */
class ServerException extends CboxBillingException {}
