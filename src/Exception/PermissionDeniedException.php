<?php

declare(strict_types=1);

namespace Cbox\Billing\Exception;

/** 403: the token lacks the scope, or acts for an organization it is not bound to. */
class PermissionDeniedException extends CboxBillingException {}
