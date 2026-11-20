<?php

declare(strict_types=1);

namespace Cbox\Billing\Exception;

/** 422: field validation failed. */
class ValidationException extends CboxBillingException
{
    /** @return array<string, list<string>> Each field's messages. */
    public function getErrors(): array
    {
        $errors = $this->getPayload()['errors'] ?? null;

        if (! is_array($errors)) {
            return [];
        }

        $byField = [];
        foreach ($errors as $field => $messages) {
            if (is_array($messages)) {
                $byField[(string) $field] = array_values(array_filter($messages, 'is_string'));
            }
        }

        return $byField;
    }
}
