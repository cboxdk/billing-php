<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/** One boolean or config feature. */
final class FeatureDecision
{
    public function __construct(
        /** `boolean`, `config`, or null. */
        public readonly ?string $type,
        public readonly bool $enabled,
        public readonly int|string|null $value,
        /** `plan`, `override` or `default`. */
        public readonly string $source,
    ) {}

    /** @return array{type: string|null, enabled: bool, value: int|string|null, source: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'enabled' => $this->enabled, 'value' => $this->value, 'source' => $this->source];
    }
}
