<?php

declare(strict_types=1);

namespace Cbox\Billing\Enforcement;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Meters usage against Cbox Billing without a request to Billing per call. Reservations take
 * units from a slice of the organization's allowance leased into the store, Billing is asked only
 * to refill, and committed usage is reported as running totals. The same client, with the same
 * behaviour, exists in every Cbox Billing SDK; sdks/conformance/SPEC.md describes it.
 *
 *     $billing = new EnforcementClient(getenv('CBOX_BILLING_TOKEN'), 'https://api.cboxbilling.com/v1',
 *         store: RedisStore::phpredis($redis));
 *     $reservation = $billing->reserve('org_acme', 'api.requests', 1);
 *     try {
 *         handle();
 *         $billing->commit($reservation, 1);
 *     } finally {
 *         $billing->release($reservation); // does nothing once committed
 *     }
 *
 * PHP has no threads, so a slice that runs low is topped up in a shutdown function (after the
 * response under PHP-FPM) when `$deferRefill` is true, the default; false tops it up before
 * `reserve()` returns.
 */
final class EnforcementClient
{
    private const INVALIDATING = [
        'entitlements.changed', 'customer.suspended', 'customer.reactivated', 'customer.deleted',
        'customer.merged', 'bundle.benefit_started', 'bundle.benefit_ended', 'agreement.accepted',
        'agreement.withdrawn',
    ];

    private const ORGANIZATION_FIELDS = ['organization_ref', 'organization_id', 'cbox_org_id', 'organization'];

    private const SWEPT_TTL = 86_400;

    private readonly Store $store;

    private readonly Transport $transport;

    private readonly Closure $clock;

    /** @var array<string, array{0: string, 1: string}> top-ups waiting for the shutdown function */
    private array $deferred = [];

    private bool $shutdownRegistered = false;

    /**
     * @param  string  $token  An API token with the `enforce` scope: `cbl_…` (live) or `cbt_…` (test).
     * @param  string  $baseUrl  Your deployment's API root, e.g. `https://api.cboxbilling.com/v1`.
     * @param  Store|null  $store  Where state lives. Default: this process's memory, which only a long-running process keeps; use ApcuStore or RedisStore under PHP-FPM.
     * @param  array<string, int>  $graceMeters  Grace per meter, overriding `$graceUnits`.
     * @param  array<string, string>  $headers  Headers sent on every request.
     * @param  (Closure(string, string, array<string, string>, ?string, float): array{0: int, 1: string})|null  $send  The HTTP function; Guzzle by default.
     * @param  (Closure(): int)|null  $clock  Milliseconds since the epoch; for tests.
     * @param  (Closure(Throwable): void)|null  $onDeferredError  Called with an error a deferred top-up hit.
     */
    public function __construct(
        string $token,
        string $baseUrl,
        ?Store $store = null,
        private readonly int $leaseSize = 100,
        private readonly int $refillThreshold = 20,
        private readonly FailurePolicy $failurePolicy = FailurePolicy::LastKnown,
        private readonly float $reservationTtl = 300,
        private readonly int $graceUnits = 100,
        private readonly float $graceWindow = 3600,
        private readonly array $graceMeters = [],
        private readonly float $breakerCooldown = 30,
        private readonly float $snapshotRefresh = 300,
        private readonly float $entitlementsTtl = 60,
        private readonly float $lockTtl = 10,
        private readonly float $lockWait = 5,
        float $timeout = 5,
        private readonly float $leaseTimeout = 2,
        private readonly string $prefix = 'cbox-billing:',
        private readonly bool $deferRefill = true,
        private readonly ?Closure $onDeferredError = null,
        array $headers = [],
        ?Closure $send = null,
        ?Closure $clock = null,
    ) {
        if ($token === '') {
            throw new InvalidArgumentException('A Cbox Billing API token is required.');
        }
        foreach (['leaseSize' => $leaseSize, 'reservationTtl' => $reservationTtl, 'graceWindow' => $graceWindow,
            'snapshotRefresh' => $snapshotRefresh, 'entitlementsTtl' => $entitlementsTtl, 'lockTtl' => $lockTtl,
            'timeout' => $timeout, 'leaseTimeout' => $leaseTimeout] as $name => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException("{$name} must be positive.");
            }
        }
        foreach (['refillThreshold' => $refillThreshold, 'graceUnits' => $graceUnits,
            'breakerCooldown' => $breakerCooldown, 'lockWait' => $lockWait] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("{$name} must be 0 or more.");
            }
        }

        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
        $this->store = $store ?? new MemoryStore($this->clock);
        $this->transport = new Transport(rtrim($baseUrl, '/'), $token, $send ?? Transport::guzzle(), $headers, $timeout);
    }

    // ---- the hot path ----

    /**
     * Hold `$estimate` units of `$meter`: locally when the slice covers it, otherwise after one lease.
     *
     * @throws QuotaExceededException when Billing granted nothing (or, in an outage, the last known allowance is spent)
     * @throws BillingRefusedException when Billing refused the lease
     * @throws BillingUnavailableException when Billing is unreachable and the failure policy refuses
     * @throws InvalidArgumentException when `$estimate` is not positive
     */
    public function reserve(string $org, string $meter, int $estimate): Reservation
    {
        if ($estimate <= 0) {
            throw new InvalidArgumentException('The estimate must be a positive integer.');
        }
        $hold = $this->hold($org, $meter, $estimate);
        $id = self::newId();
        $this->open($id, $org, [$hold]);
        if ($hold->kind === HoldKind::Lease) {
            $this->refillIfLow($org, $meter);
        }

        return new Reservation($id, $org, $hold);
    }

    /**
     * Hold several meters at once, in key order, all or nothing: when one cannot be held, the
     * others go back and this throws what the failing meter threw.
     *
     * @param  array<string, int>  $meters
     */
    public function reserveMany(string $org, array $meters): ReservationSet
    {
        if ($meters === []) {
            throw new InvalidArgumentException('A reservation set needs at least one meter.');
        }
        ksort($meters, SORT_STRING);
        foreach ($meters as $name => $estimate) {
            if (! is_int($estimate) || $estimate <= 0) {
                throw new InvalidArgumentException("The estimate for [{$name}] must be a positive integer.");
            }
        }

        $held = [];
        try {
            foreach ($meters as $name => $estimate) {
                $held[] = $this->hold($org, (string) $name, $estimate);
            }
        } catch (Throwable $e) {
            foreach ($held as $hold) {
                $this->giveBack($org, $hold, $hold->amount);
            }
            throw $e;
        }

        $id = self::newId();
        $this->open($id, $org, $held);
        foreach ($held as $hold) {
            if ($hold->kind === HoldKind::Lease) {
                $this->refillIfLow($org, $hold->meter);
            }
        }

        return new ReservationSet($id, $org, $held);
    }

    /**
     * Settle a reservation to what was used: an integer for a Reservation, `[meter => actual]`
     * covering every meter for a ReservationSet, each from 0 to what was held. The rest goes back;
     * the usage is recorded for reporting.
     *
     * @param  int|array<string, int>  $actual
     *
     * @throws InvalidArgumentException when an actual is out of range or missing, or the reservation is already settled
     */
    public function commit(Reservation|ReservationSet $reservation, int|array $actual): void
    {
        $holds = $reservation instanceof Reservation ? [$reservation->hold] : $reservation->holds;
        $actuals = self::actualsFor($reservation, $holds, $actual);
        $org = $reservation->org;

        if ($this->store->delete($this->key('res', $reservation->id))) {
            foreach ($holds as $i => $hold) {
                $this->giveBack($org, $hold, $hold->amount - $actuals[$i]);
            }
        } elseif ($this->store->delete($this->key('swept', $reservation->id))) {
            // The sweeper gave every unit back; take out again what was used, as far as the slice allows.
            foreach ($holds as $i => $hold) {
                if ($hold->kind === HoldKind::Lease && $actuals[$i] > 0) {
                    $this->take($org, $hold->meter, $actuals[$i]);
                }
            }
        } else {
            throw new InvalidArgumentException("Reservation [{$reservation->id}] is already settled.");
        }

        foreach ($holds as $i => $hold) {
            if ($actuals[$i] > 0) {
                $this->record($org, $hold->meter, $actuals[$i]);
            }
        }
    }

    /** Give a reservation's units back without recording usage. Does nothing once it is settled. */
    public function release(Reservation|ReservationSet $reservation): void
    {
        $holds = $reservation instanceof Reservation ? [$reservation->hold] : $reservation->holds;
        if ($this->store->delete($this->key('res', $reservation->id))) {
            foreach ($holds as $hold) {
                $this->giveBack($reservation->org, $hold, $hold->amount);
            }

            return;
        }
        $this->store->delete($this->key('swept', $reservation->id));
    }

    /** Could `$n` units be reserved now? Refills once if short; takes nothing; never throws for Billing. */
    public function can(string $org, string $meter, int $n): bool
    {
        if ($n <= 0) {
            return false;
        }
        if ($this->balance($org, $meter) >= $n) {
            return true;
        }
        try {
            $this->refill($org, $meter, $n, false);
        } catch (BillingUnavailableException) {
            return $this->outageAdmits($org, $meter, $n);
        } catch (EnforcementException) {
            return false;
        }

        return $this->balance($org, $meter) >= $n;
    }

    /** The units in the local slice. */
    public function balance(string $org, string $meter): int
    {
        return $this->counter($this->key('lease', $org, $meter));
    }

    // ---- reporting and upkeep ----

    /**
     * Send the buffered running totals to Billing: one request per organization, its meters in key
     * order, leaving out streams unchanged since their last accepted report. Returns how many
     * organizations were reported; one that fails is sent again next time.
     */
    public function report(?string $org = null): int
    {
        $base = $this->key('usage').':';
        $prefix = $org === null ? $base : $base.self::escape($org).':';
        $streams = [];
        foreach ($this->store->keys($prefix) as $key) {
            $parts = explode(':', substr($key, strlen($base)));
            if (count($parts) !== 2) {
                continue;
            }
            $streams[self::unescape($parts[0])][] = self::unescape($parts[1]);
        }
        ksort($streams, SORT_STRING);

        $reported = 0;
        foreach ($streams as $owner => $meters) {
            $owner = (string) $owner;
            sort($meters, SORT_STRING);
            $entries = [];
            foreach ($meters as $meter) {
                // The sequence first: a commit raises the total before the sequence, so this total
                // is at least the one this sequence names, and a later commit changes it again.
                $seq = $this->counter($this->key('useq', $owner, $meter));
                $cumulative = $this->counter($this->key('usage', $owner, $meter));
                $sent = $this->store->get($this->key('usent', $owner, $meter));
                if ($sent !== null && (int) $sent === $seq) {
                    continue;
                }
                $entries[] = ['meter' => $meter, 'cumulative' => $cumulative, 'seq' => $seq,
                    'epoch' => $this->counter($this->key('uepoch', $owner, $meter))];
            }
            if ($entries === []) {
                continue;
            }
            try {
                $this->transport->post('/usage', ['org' => $owner, 'entries' => $entries]);
            } catch (EnforcementException) {
                continue;
            }
            foreach ($entries as $entry) {
                $this->store->put($this->key('usent', $owner, $entry['meter']), (string) $entry['seq']);
            }
            $reported++;
        }

        return $reported;
    }

    /**
     * Close every reservation older than the reservation TTL and give its units back. Returns how
     * many. Run it on a schedule, about as often as the TTL.
     */
    public function sweep(): int
    {
        $base = $this->key('res').':';
        $now = ($this->clock)();
        $swept = 0;
        foreach ($this->store->keys($base) as $key) {
            $raw = $this->store->get($key);
            $stored = $raw === null ? null : self::parseReservation($raw);
            if ($stored === null || $stored['expires_at'] > $now || ! $this->store->delete($key)) {
                continue;
            }
            $this->store->put($this->key('swept', self::unescape(substr($key, strlen($base)))), '1', self::SWEPT_TTL);
            foreach ($stored['holds'] as $hold) {
                $this->giveBack($stored['org'], $hold, $hold->amount);
            }
            $swept++;
        }

        return $swept;
    }

    /** Run the top-ups deferred so far now. The shutdown function calls it too. */
    public function flushDeferred(): void
    {
        $pending = $this->deferred;
        $this->deferred = [];
        foreach ($pending as [$org, $meter]) {
            try {
                $this->refill($org, $meter, $this->refillThreshold + 1, true);
            } catch (Throwable $e) {
                if ($this->onDeferredError !== null) {
                    ($this->onDeferredError)($e);
                }
            }
        }
    }

    // ---- entitlements and features ----

    /**
     * The organization's per-meter entitlements, cached for the entitlements TTL. When Billing
     * cannot be reached, the last answer, marked stale.
     */
    public function entitlements(string $org): Entitlements
    {
        $cached = $this->readMeters($org);
        if ($cached !== null && $this->store->get($this->key('entfresh', $org)) !== null) {
            return new Entitlements($org, $cached, false);
        }
        try {
            return $this->fetchEntitlements($org);
        } catch (BillingUnavailableException $e) {
            if ($cached !== null) {
                return new Entitlements($org, $cached, true);
            }
            throw $e;
        }
    }

    /** The organization's boolean and config features, cached like entitlements(). */
    public function features(string $org): Features
    {
        $raw = $this->store->get($this->key('feat', $org));
        $cached = $raw === null ? null : self::featuresFrom($org, json_decode($raw, true), false);
        if ($cached !== null && $this->store->get($this->key('featfresh', $org)) !== null) {
            return $cached;
        }
        try {
            $endpoint = '/entitlements/'.rawurlencode($org).'/features';
            $parsed = self::parseFeatures($this->transport->get($endpoint), $endpoint);
            $this->store->put($this->key('feat', $org), json_encode($parsed, JSON_THROW_ON_ERROR));
            $this->store->put($this->key('featfresh', $org), '1', $this->entitlementsTtl);

            return self::featuresFrom($org, $parsed, false) ?? throw Transport::malformed($endpoint, 'unreadable features');
        } catch (BillingUnavailableException $e) {
            if ($cached !== null) {
                return new Features($org, $cached->features, $cached->suspended, true);
            }
            throw $e;
        }
    }

    /** True when the feature is granted and the account is not suspended. */
    public function hasFeature(string $org, string $key): bool
    {
        return $this->features($org)->enabled($key);
    }

    /** A config feature's value, true for a granted boolean feature, null otherwise. */
    public function featureValue(string $org, string $key): int|string|bool|null
    {
        return $this->features($org)->value($key);
    }

    /** `units × weight` for the meter, from the cached entitlements. */
    public function cost(string $org, string $meter, int $units): float
    {
        return $this->entitlements($org)->cost($meter, $units);
    }

    /**
     * Make the next read for `$org` (or for everyone) ask Billing, and the next refill read the
     * entitlements again. The old answer stays, as the fallback for an outage.
     */
    public function invalidate(?string $org = null): void
    {
        foreach (['entfresh', 'featfresh', 'claim'] as $kind) {
            if ($org !== null) {
                $this->store->delete($this->key($kind, $org));

                continue;
            }
            foreach ($this->store->keys($this->key($kind).':') as $key) {
                $this->store->delete($key);
            }
        }
    }

    /**
     * Invalidate from a verified webhook event (see Webhook::constructEvent): `['type' => …,
     * 'data' => […]]`. Returns true when the event type changes what a customer holds.
     *
     * @param  array<string, mixed>  $event
     */
    public function handleWebhook(array $event): bool
    {
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        if ($type === 'plan.changed') {
            $this->invalidate();

            return true;
        }
        if (! in_array($type, self::INVALIDATING, true) && ! str_starts_with($type, 'subscription.')) {
            return false;
        }
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $orgs = [];
        foreach (self::ORGANIZATION_FIELDS as $field) {
            if (is_string($data[$field] ?? null) && $data[$field] !== '') {
                $orgs[$data[$field]] = true;
            }
        }
        if ($orgs === []) {
            $this->invalidate();
        }
        foreach (array_keys($orgs) as $org) {
            $this->invalidate((string) $org);
        }

        return true;
    }

    // ---- holding units ----

    private function hold(string $org, string $meter, int $estimate): Hold
    {
        if ($this->take($org, $meter, $estimate)) {
            return new Hold($meter, $estimate, HoldKind::Lease);
        }
        try {
            $answer = $this->refill($org, $meter, $estimate, false, true, $held);
        } catch (BillingUnavailableException $outage) {
            return $this->outageHold($org, $meter, $estimate, $outage);
        }
        // Unless the refill held it, Billing granted less than the estimate; the slice may still cover the rest.
        if ($held || $this->take($org, $meter, $estimate)) {
            return new Hold($meter, $estimate, HoldKind::Lease);
        }

        throw new QuotaExceededException($org, $meter, $estimate, $answer['reason'] ?? 'quota_exhausted');
    }

    private function outageHold(string $org, string $meter, int $estimate, BillingUnavailableException $outage): Hold
    {
        if ($this->failurePolicy === FailurePolicy::Allow) {
            return new Hold($meter, $estimate, HoldKind::Allowed);
        }
        if ($this->failurePolicy === FailurePolicy::Deny) {
            throw $outage;
        }

        $known = $this->lastKnown($org, $meter);
        if ($known !== null) {
            if ($this->admit($org, $meter, $estimate, $known)) {
                return new Hold($meter, $estimate, HoldKind::Admitted);
            }
            if (! $known->enabled) {
                throw new QuotaExceededException($org, $meter, $estimate, 'meter_disabled');
            }
        }

        $window = $this->takeGrace($org, $meter, $estimate);
        if ($window !== null) {
            return new Hold($meter, $estimate, HoldKind::Grace, $window);
        }
        if ($known === null) {
            throw $outage;
        }

        throw new QuotaExceededException($org, $meter, $estimate, 'outage_limit');
    }

    private function outageAdmits(string $org, string $meter, int $n): bool
    {
        if ($this->failurePolicy !== FailurePolicy::LastKnown) {
            return $this->failurePolicy === FailurePolicy::Allow;
        }
        $known = $this->lastKnown($org, $meter);
        if ($known !== null) {
            if (! $known->enabled) {
                return false;
            }
            if (! $known->capped() || $this->headroom($org, $meter, $known) >= $n) {
                return true;
            }
        }

        return $this->graceRemaining($org, $meter) >= $n;
    }

    // ---- refilling ----

    /**
     * Lease at least `$want` units into the slice, single flight per (organization, meter). Returns
     * Billing's answer, or null when another refill already filled the slice.
     *
     * With `$claim` it also holds `$want` for the caller, and sets `$held` to whether it did: from
     * the slice while it holds the lock, or else straight from the grant, so no concurrent
     * reservation can take them between the refill and the caller's take (#427). It holds nothing
     * when Billing granted less.
     *
     * @param-out bool $held
     *
     * @return array{granted: int, reason: string|null}|null
     */
    private function refill(string $org, string $meter, int $want, bool $probe, bool $claim = false, ?bool &$held = null): ?array
    {
        $held = false;
        $this->guard($probe);
        $lock = $this->key('lock', $org, $meter);
        $token = self::newId();
        $deadline = microtime(true) + $this->lockWait;
        $acquired = $this->store->add($lock, $token, $this->lockTtl);
        while (! $acquired && ! $probe && microtime(true) < $deadline) {
            usleep(25_000);
            $acquired = $this->store->add($lock, $token, $this->lockTtl);
        }

        if (! $acquired) {
            if ($probe) {
                return null;
            }
            // Could not join the single flight in time: refill directly rather than starve.
            $this->guard($probe);

            return $this->lease($org, $meter, $want, $claim, $held);
        }

        try {
            if ($claim) {
                if ($held = $this->take($org, $meter, $want)) {
                    return null;
                }
            } elseif ($this->balance($org, $meter) >= $want) {
                return null;
            }
            $this->guard($probe);

            return $this->lease($org, $meter, $want, $claim, $held);
        } finally {
            if ($this->store->get($lock) === $token) {
                $this->store->delete($lock);
            }
        }
    }

    /**
     * Lease a slice and add the grant to it. With `$claim`, when the grant covers `$want`, keep
     * `$want` back for the caller and set `$held`.
     *
     * @param-out bool $held
     *
     * @return array{granted: int, reason: string|null}
     */
    private function lease(string $org, string $meter, int $want, bool $claim = false, ?bool &$held = null): array
    {
        $held = false;
        $size = max($this->leaseSize, $want);
        try {
            $answer = self::parseLease($this->transport->post('/leases', ['org' => $org, 'meter' => $meter, 'size' => $size], $this->leaseTimeout));
        } catch (BillingUnavailableException $e) {
            if ($this->breakerCooldown > 0) {
                $this->store->put($this->key('breaker'), '1', $this->breakerCooldown);
            }
            throw $e;
        }

        $this->store->delete($this->key('breaker'));
        $kept = $claim && $answer['granted'] >= $want ? $want : 0;
        if ($answer['granted'] > $kept) {
            $this->store->increment($this->key('lease', $org, $meter), $answer['granted'] - $kept);
        }
        try {
            if ($this->store->add($this->key('claim', $org), '1', $this->snapshotRefresh)) {
                try {
                    $this->fetchEntitlements($org);
                } catch (EnforcementException) {
                    // Keep the previous snapshot; the next interval reads again.
                }
            }
            $this->recordLease($org, $meter, $size, $answer['granted']);
        } catch (Throwable $e) {
            // The caller gets the error, not a hold, so its units go to the slice after all.
            if ($kept > 0) {
                $this->store->increment($this->key('lease', $org, $meter), $kept);
            }
            throw $e;
        }
        $held = $kept > 0;

        return $answer;
    }

    private function refillIfLow(string $org, string $meter): void
    {
        if ($this->balance($org, $meter) > $this->refillThreshold) {
            return;
        }
        if ($this->deferRefill) {
            $this->deferred[$org."\0".$meter] = [$org, $meter];
            if (! $this->shutdownRegistered) {
                $this->shutdownRegistered = true;
                register_shutdown_function(function (): void {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    $this->flushDeferred();
                });
            }

            return;
        }
        try {
            $this->refill($org, $meter, $this->refillThreshold + 1, false);
        } catch (EnforcementException) {
            // The next reservation on a spent slice refills, or applies the policy.
        }
    }

    private function guard(bool $probe): void
    {
        if (! $probe && $this->breakerCooldown > 0 && $this->store->get($this->key('breaker')) !== null) {
            throw new BillingUnavailableException('Billing was unreachable moments ago; the breaker is open.', '/leases');
        }
    }

    // ---- the last known allowance ----

    private function fetchEntitlements(string $org): Entitlements
    {
        $endpoint = '/entitlements/'.rawurlencode($org);
        $meters = self::parseMeters($this->transport->get($endpoint), $endpoint);
        $this->store->put($this->key('ent', $org), json_encode(['meters' => array_map(
            static fn (MeterEntitlement $m): array => $m->toArray(), $meters,
        )], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_FORCE_OBJECT));
        $this->store->put($this->key('entfresh', $org), '1', $this->entitlementsTtl);

        return new Entitlements($org, $meters, false);
    }

    /** @return array<string, MeterEntitlement>|null */
    private function readMeters(string $org): ?array
    {
        $raw = $this->store->get($this->key('ent', $org));
        $stored = $raw === null ? null : json_decode($raw, true);
        if (! is_array($stored)) {
            return null;
        }
        try {
            return self::parseMeters($stored, '');
        } catch (EnforcementException) {
            return null;
        }
    }

    private function lastKnown(string $org, string $meter): ?MeterEntitlement
    {
        return $this->readMeters($org)[$meter] ?? null;
    }

    /**
     * Count a lease Billing answered. A short grant on a capped meter means Billing has nothing
     * left; a full grant past the allowance means a new period began, so only it counts.
     */
    private function recordLease(string $org, string $meter, int $requested, int $granted): void
    {
        $known = $this->lastKnown($org, $meter);
        $drawn = $this->key('drawn', $org, $meter);
        if ($known === null || ! $known->capped()) {
            if ($granted > 0) {
                $this->store->increment($drawn, $granted);
            }

            return;
        }
        $allowance = (int) $known->allowance;
        if ($granted < $requested) {
            $this->store->put($drawn, (string) $allowance);

            return;
        }
        if ($this->store->increment($drawn, $granted) > $allowance) {
            $this->store->put($drawn, (string) $granted);
            $this->store->put($this->key('admitted', $org, $meter), '0');
        }
    }

    private function admit(string $org, string $meter, int $units, MeterEntitlement $known): bool
    {
        if (! $known->enabled) {
            return false;
        }
        $key = $this->key('admitted', $org, $meter);
        $after = $this->store->increment($key, $units);
        if (! $known->capped()) {
            return true;
        }
        if ((int) $known->allowance - $this->counter($this->key('drawn', $org, $meter)) - $after < 0) {
            $this->store->increment($key, -$units);

            return false;
        }

        return true;
    }

    private function headroom(string $org, string $meter, MeterEntitlement $known): int
    {
        if (! $known->capped()) {
            return 0;
        }

        return max(0, (int) $known->allowance
            - $this->counter($this->key('drawn', $org, $meter))
            - $this->counter($this->key('admitted', $org, $meter)));
    }

    // ---- outage grace ----

    private function graceLimit(string $meter): int
    {
        return max(0, $this->graceMeters[$meter] ?? $this->graceUnits);
    }

    private function currentWindow(): int
    {
        return intdiv(($this->clock)(), (int) round($this->graceWindow * 1000));
    }

    private function takeGrace(string $org, string $meter, int $units): ?int
    {
        $limit = $this->graceLimit($meter);
        if ($units <= 0 || $units > $limit) {
            return null;
        }
        $window = $this->currentWindow();
        $key = $this->key('grace', $org, $meter, (string) $window);
        $this->store->add($key, '0', $this->graceWindow);
        if ($this->store->increment($key, $units) > $limit) {
            $this->store->increment($key, -$units);

            return null;
        }

        return $window;
    }

    private function graceRemaining(string $org, string $meter): int
    {
        $used = $this->counter($this->key('grace', $org, $meter, (string) $this->currentWindow()));

        return max(0, $this->graceLimit($meter) - $used);
    }

    // ---- store helpers ----

    /** Return units to where a hold took them from. */
    private function giveBack(string $org, Hold $hold, int $units): void
    {
        if ($units <= 0) {
            return;
        }
        switch ($hold->kind) {
            case HoldKind::Lease:
                $this->store->increment($this->key('lease', $org, $hold->meter), $units);
                break;
            case HoldKind::Admitted:
                $this->store->increment($this->key('admitted', $org, $hold->meter), -$units);
                break;
            case HoldKind::Grace:
                // Only to the window it came from, and only while it exists.
                $key = $this->key('grace', $org, $hold->meter, (string) $hold->graceWindow);
                if ($this->store->get($key) !== null) {
                    $this->store->increment($key, -$units);
                }
                break;
            case HoldKind::Allowed:
                break;
        }
    }

    /** Take `$amount` from the slice if it holds that much. Under concurrency it can only over-refuse. */
    private function take(string $org, string $meter, int $amount): bool
    {
        $key = $this->key('lease', $org, $meter);
        if ($this->store->increment($key, -$amount) < 0) {
            $this->store->increment($key, $amount);

            return false;
        }

        return true;
    }

    private function record(string $org, string $meter, int $units): void
    {
        $this->store->add($this->key('uepoch', $org, $meter), (string) ($this->clock)());
        $this->store->increment($this->key('usage', $org, $meter), $units);
        $this->store->increment($this->key('useq', $org, $meter), 1);
    }

    /** @param  list<Hold>  $holds */
    private function open(string $id, string $org, array $holds): void
    {
        $this->store->put($this->key('res', $id), json_encode([
            'org' => $org,
            'expires_at' => ($this->clock)() + (int) round($this->reservationTtl * 1000),
            'holds' => array_map(static fn (Hold $h): array => $h->toArray(), $holds),
        ], JSON_THROW_ON_ERROR));
    }

    private function counter(string $key): int
    {
        $value = $this->store->get($key);

        return $value !== null && is_numeric($value) ? (int) $value : 0;
    }

    private function key(string $kind, string ...$parts): string
    {
        $key = $this->prefix.$kind;
        foreach ($parts as $part) {
            $key .= ':'.self::escape($part);
        }

        return $key;
    }

    // ---- parsing ----

    /**
     * @param  array<string, mixed>  $body
     * @return array{granted: int, reason: string|null}
     */
    private static function parseLease(array $body): array
    {
        $granted = $body['granted'] ?? null;
        if (! is_int($granted) || $granted < 0) {
            throw Transport::malformed('/leases', 'missing an integer granted of at least 0');
        }
        if (is_array($body['blocked'] ?? null)) {
            $reason = $body['blocked']['reason'] ?? null;

            return ['granted' => $granted, 'reason' => is_string($reason) ? $reason : 'agreement_required'];
        }
        $reason = $body['reason'] ?? null;

        return ['granted' => $granted, 'reason' => is_string($reason) && $reason !== '' ? $reason : null];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, MeterEntitlement>
     */
    private static function parseMeters(array $body, string $endpoint): array
    {
        $raw = $body['meters'] ?? null;
        if (! is_array($raw) || (array_is_list($raw) && $raw !== [])) {
            throw Transport::malformed($endpoint, 'meters must be an object');
        }
        $meters = [];
        foreach ($raw as $meter => $entry) {
            $meter = (string) $meter;
            if (! is_array($entry) || ! is_bool($entry['enabled'] ?? null)) {
                throw Transport::malformed($endpoint, "meter [{$meter}] needs a boolean enabled");
            }
            $allowance = $entry['allowance'] ?? null;
            $weight = $entry['weight'] ?? null;
            $overage = $entry['overage'] ?? 'block';
            if ($allowance !== null && ! is_int($allowance)) {
                throw Transport::malformed($endpoint, "meter [{$meter}] has a non-integer allowance");
            }
            if ($weight !== null && ! is_int($weight) && ! is_float($weight)) {
                throw Transport::malformed($endpoint, "meter [{$meter}] has a non-numeric weight");
            }
            if (! is_string($overage)) {
                throw Transport::malformed($endpoint, "meter [{$meter}] has a non-string overage");
            }
            $meters[$meter] = new MeterEntitlement($entry['enabled'], $allowance, $weight === null ? null : (float) $weight, $overage);
        }

        return $meters;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{features: array<string, array{type: string|null, enabled: bool, value: int|string|null, source: string}>, suspended: bool}
     */
    private static function parseFeatures(array $body, string $endpoint): array
    {
        $raw = $body['features'] ?? null;
        $account = $body['account'] ?? null;
        if (! is_array($raw) || (array_is_list($raw) && $raw !== []) || ! is_array($account) || ! is_bool($account['suspended'] ?? null)) {
            throw Transport::malformed($endpoint, 'expected features and account.suspended');
        }
        $features = [];
        foreach ($raw as $key => $entry) {
            $key = (string) $key;
            $type = is_array($entry) ? ($entry['type'] ?? null) : false;
            $value = is_array($entry) ? ($entry['value'] ?? null) : false;
            $source = is_array($entry) ? ($entry['source'] ?? 'default') : false;
            if (! is_array($entry) || ! is_bool($entry['enabled'] ?? null)
                || ! in_array($type, ['boolean', 'config', null], true)
                || ! ($value === null || is_int($value) || is_string($value))
                || ! is_string($source)) {
                throw Transport::malformed($endpoint, "feature [{$key}] is not a feature decision");
            }
            $features[$key] = ['type' => $type, 'enabled' => $entry['enabled'], 'value' => $value, 'source' => $source];
        }

        return ['features' => $features, 'suspended' => $account['suspended']];
    }

    private static function featuresFrom(string $org, mixed $stored, bool $stale): ?Features
    {
        if (! is_array($stored) || ! is_array($stored['features'] ?? null)) {
            return null;
        }
        $features = [];
        foreach ($stored['features'] as $key => $f) {
            $features[(string) $key] = new FeatureDecision($f['type'], (bool) $f['enabled'], $f['value'], (string) $f['source']);
        }

        return new Features($org, $features, (bool) ($stored['suspended'] ?? false), $stale);
    }

    /** @return array{org: string, expires_at: int, holds: list<Hold>}|null */
    private static function parseReservation(string $raw): ?array
    {
        $data = json_decode($raw, true);
        if (! is_array($data) || ! is_string($data['org'] ?? null) || ! is_int($data['expires_at'] ?? null) || ! is_array($data['holds'] ?? null)) {
            return null;
        }
        $holds = [];
        foreach ($data['holds'] as $row) {
            $hold = is_array($row) ? Hold::fromArray($row) : null;
            if ($hold === null) {
                return null;
            }
            $holds[] = $hold;
        }

        return ['org' => $data['org'], 'expires_at' => $data['expires_at'], 'holds' => $holds];
    }

    /**
     * @param  list<Hold>  $holds
     * @param  int|array<string, int>  $actual
     * @return list<int>
     */
    private static function actualsFor(Reservation|ReservationSet $reservation, array $holds, int|array $actual): array
    {
        if ($reservation instanceof Reservation) {
            if (! is_int($actual)) {
                throw new InvalidArgumentException('A single-meter reservation is committed with an integer.');
            }

            return [self::inRange($actual, $holds[0])];
        }
        if (! is_array($actual)) {
            throw new InvalidArgumentException('A reservation set is committed with an actual per meter.');
        }
        $names = array_map(static fn (Hold $h): string => $h->meter, $holds);
        foreach (array_keys($actual) as $meter) {
            if (! in_array((string) $meter, $names, true)) {
                throw new InvalidArgumentException("Meter [{$meter}] is not in this reservation.");
            }
        }
        $result = [];
        foreach ($holds as $hold) {
            if (! array_key_exists($hold->meter, $actual)) {
                throw new InvalidArgumentException("Missing the actual for meter [{$hold->meter}].");
            }
            $result[] = self::inRange($actual[$hold->meter], $hold);
        }

        return $result;
    }

    private static function inRange(mixed $actual, Hold $hold): int
    {
        if (! is_int($actual) || $actual < 0 || $actual > $hold->amount) {
            throw new InvalidArgumentException("The actual for meter [{$hold->meter}] must be an integer from 0 to {$hold->amount}.");
        }

        return $actual;
    }

    /** Keys join parts with `:`; a part's own `%` and `:` are escaped so every key splits back. */
    private static function escape(string $part): string
    {
        return str_replace(['%', ':'], ['%25', '%3A'], $part);
    }

    private static function unescape(string $part): string
    {
        return str_replace(['%3A', '%25'], [':', '%'], $part);
    }

    private static function newId(): string
    {
        return 'rsv_'.bin2hex(random_bytes(16));
    }
}
