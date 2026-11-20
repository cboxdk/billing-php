<?php

declare(strict_types=1);

namespace Cbox\Billing\Exception;

use RuntimeException;

/** The delivery is not signed by Cbox Billing with this secret, or is too old. */
class WebhookVerificationException extends RuntimeException {}
