<?php

declare(strict_types=1);

namespace Cbox\Billing\Exception;

/** 429: throttled. */
class RateLimitException extends CboxBillingException
{
    /** The server's `Retry-After`, in seconds, or null. */
    public function getRetryAfter(): ?int
    {
        foreach ($this->getResponseHeaders() ?? [] as $name => $values) {
            if (strcasecmp((string) $name, 'Retry-After') === 0) {
                $value = is_array($values) ? ($values[0] ?? '') : $values;

                return ctype_digit((string) $value) ? (int) $value : null;
            }
        }

        return null;
    }
}
