<?php

declare(strict_types=1);

namespace Cbox\Billing;

final class Idempotency
{
    /**
     * A fresh `Idempotency-Key` (a random UUID v4). Generate it once per business action and keep
     * it with that action, so a retry after a timeout sends the same key and the server replays
     * the first response instead of doing the work twice.
     */
    public static function newKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
