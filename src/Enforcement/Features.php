<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/** An organization's boolean and config features. Deny by default; a suspended account has none. */
final class Features
{
    /** @param  array<string, FeatureDecision>  $features */
    public function __construct(
        public readonly string $org,
        public readonly array $features,
        public readonly bool $suspended,
        public readonly bool $stale,
    ) {}

    public function for(string $key): ?FeatureDecision
    {
        return $this->features[$key] ?? null;
    }

    public function enabled(string $key): bool
    {
        return ! $this->suspended && $this->for($key)?->enabled === true;
    }

    /** A config feature's value, true for a boolean feature, null when not granted. */
    public function value(string $key): int|string|bool|null
    {
        if (! $this->enabled($key)) {
            return null;
        }
        $feature = $this->for($key);

        return $feature?->type === 'config' ? $feature->value : true;
    }
}
