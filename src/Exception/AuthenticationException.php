<?php

declare(strict_types=1);

namespace Cbox\Billing\Exception;

/** 401: no token, or a token the server does not know. */
class AuthenticationException extends CboxBillingException {}
