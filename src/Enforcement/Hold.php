<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

/** The units held on one meter. */
final class Hold
{
    public function __construct(
        public readonly string $meter,
        public readonly int $amount,
        public readonly HoldKind $kind,
        /** The window a grace hold was taken from; null otherwise. */
        public readonly ?int $graceWindow = null,
    ) {}

    /** True when the units were taken from a leased slice. */
    public function backedByLease(): bool
    {
        return $this->kind === HoldKind::Lease;
    }

    /** True when the units came from the outage grace. */
    public function grace(): bool
    {
        return $this->kind === HoldKind::Grace;
    }

    /** @return array{meter: string, amount: int, kind: string, grace_window: int|null} */
    public function toArray(): array
    {
        return ['meter' => $this->meter, 'amount' => $this->amount, 'kind' => $this->kind->value, 'grace_window' => $this->graceWindow];
    }

    /** @param  array<string, mixed>  $row */
    public static function fromArray(array $row): ?self
    {
        $kind = HoldKind::tryFrom((string) ($row['kind'] ?? ''));
        if ($kind === null || ! is_string($row['meter'] ?? null) || ! is_int($row['amount'] ?? null)) {
            return null;
        }

        return new self($row['meter'], $row['amount'], $kind, is_int($row['grace_window'] ?? null) ? $row['grace_window'] : null);
    }
}
