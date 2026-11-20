# The enforcement client: one behaviour in five languages

Every SDK ships an `EnforcementClient` that meters usage against Cbox Billing on the hot path
without a network round-trip per request. It is the same design as
`cboxdk/laravel-billing-client` (Cbox's own Laravel apps use that one), and it behaves the same
in PHP, TypeScript, Python, Go and Ruby. The scenarios in [`scenarios/`](scenarios) pin that
behaviour down; each SDK's test suite runs every one of them and must pass all.

## The two tiers

1. **The hot path is local.** A reservation takes units from a leased slice of the organization's
   allowance held in the client's store, with an atomic decrement. No call to Billing.
2. **Billing is asked only to refill.** When the slice is too small for a reservation, the client
   leases a new slice (`POST /leases`) of at least `lease_size` units, or the reservation's size if
   that is larger. When a reservation leaves the slice at or below `refill_threshold`, the client
   tops it up (in the background where the language allows it), so the next request rarely waits.

The enforcement client talks to Billing with its own small HTTP transport and **never retries**:
a lease that fails goes straight to the failure policy, and a report that fails is sent again by
the next report. Leases use `lease_timeout`, everything else `timeout`.

Committed usage goes to a usage buffer in the store and is reported to Billing as running totals
(`POST /usage`): per (organization, meter) the lifetime total, a sequence number and an epoch.
Reporting is cumulative, so a report that fails is simply sent again, larger, next time, and a
retry never double-counts. The epoch is set when the counter is first written, so a fresh counter
(a restarted process on the memory store, or a flushed Redis) starts a new stream instead of
looking like a regression.

Leasing is pessimistic: Billing reserves what it grants, so an organization can overshoot its
allowance by at most the unspent slices held across nodes.

## Operations

| Operation | What it does |
| --- | --- |
| `reserve(org, meter, estimate)` | Hold `estimate` units (a positive integer). Returns a reservation. |
| `reserveMany(org, {meter: estimate})` | Hold several meters at once, in key order, all or nothing: if one cannot be held, those already held are returned and the call fails. |
| `commit(reservation, actual)` | Settle to what was used (`0 ≤ actual ≤ estimate`; a map per meter for a set, covering every meter). The rest goes back to the slice; `actual` is recorded for reporting. |
| `release(reservation)` | Give the whole hold back (the work did not happen). |
| `can(org, meter, n)` | Could `n` units be reserved now? Refills once if the slice is short; takes nothing. Never throws for a Billing failure. |
| `balance(org, meter)` | The units in the local slice. |
| `report(org?)` | Send the buffered running totals now, one request per organization with its meters in key order. Returns how many organizations were reported; one that fails is left for the next report. A stream unchanged since its last accepted report is not sent. |
| `sweep()` | Close every reservation older than `reservation_ttl` and give its units back where they came from (the slice, or the outage count). Returns how many. Run it on a schedule. |
| `entitlements(org)` | The organization's per-meter entitlements, cached for `entitlements_ttl`. |
| `features(org)`, `hasFeature(org, key)`, `featureValue(org, key)` | Its boolean and config features, cached the same way. Deny by default. |
| `invalidate(org?)` | Make the next read for one organization, or for all, ask Billing. |
| `handleWebhook(event)` | Invalidate from a verified webhook event. Returns true when the event type invalidates. |

## Settling a reservation exactly once

The store records every open reservation. Settling closes it, and only the call that closes it
gives units back:

- A second `commit`, or a `commit` after `release`, is an invalid-argument error: nothing moves.
- A `release` of a reservation already settled does nothing, so `release` in a `finally` block
  after a `commit` is safe.
- A reservation the sweeper reclaimed (its request outlived `reservation_ttl`) has had all its
  units returned. Its late `commit` still records the usage and takes those units out of the slice
  again (best effort); its late `release` does nothing more. An outage reservation's late `commit`
  only records the usage.

## When the client cannot hold units

Three different causes, three different errors:

| Cause | Error | Depends on the failure policy? |
| --- | --- | --- |
| Billing answered and granted nothing: the allowance is spent, the meter is disabled, a pay-as-you-go pool or overage cap is reached, or an agreement is not accepted | `QuotaExceeded`, with a `reason` | No. Always refused. |
| Billing refused the call (a 4xx other than 408 and 429, such as a revoked token) | `BillingRefused`, with the status | No. Always refused. |
| Billing could not be reached (a network error, a timeout, a 5xx, 408, 429, or a malformed answer) | `BillingUnavailable` | Yes. |

`QuotaExceeded.reason` is one of: `quota_exhausted`; the lease's own `reason`
(`credit_limit_reached`, `overage_cap_reached`, `seat_required`); `agreement_required` when the
lease is blocked by an agreement; and, during an outage under `last_known`, `outage_limit` or
`meter_disabled`.

### Failure policy (Billing unreachable and the slice too small)

- **`last_known`** (the default): admit up to the allowance the client last knew. It counts what
  Billing leased to it (`drawn`) and what it admitted without a lease (`admitted`); a capped meter
  admits while `allowance − drawn − admitted` covers the request. An unlimited meter, or one whose
  overage is billed, keeps admitting. A meter last known disabled is refused (`meter_disabled`).
  When none of that covers the request, the **outage grace** may: `grace_units` per (organization,
  meter) per `grace_window` seconds (`grace_meters` overrides it per meter; 0 turns it off). Past
  that it refuses: `outage_limit` when an entitlement is known, `BillingUnavailable` when none is.
- **`allow`**: admit everything.
- **`deny`**: refuse with `BillingUnavailable`.

A reservation admitted without a lease is marked not lease-backed. Its unused units go back to
the outage count they came from on commit or release. Everything admitted is still committed and
reported, and Billing settles it once it is back.

### The breaker

When a refill finds Billing unreachable, the breaker opens for `breaker_cooldown` seconds (shared
through the store). While it is open no reservation tries Billing; it goes straight to the failure
policy instead of waiting out a timeout. A background top-up still tries, and the first refill that
gets an answer closes the breaker.

### Single flight

A refill takes a lock in the store (`lock_ttl`, waiting up to `lock_wait`), and checks the slice
again once it holds it, so a burst that empties a slice causes one lease, not one per request.

## The last known allowance

Every answered lease is recorded. The first lease for an organization, and the first after each
`snapshot_refresh` seconds, also reads its entitlements, so the client has an allowance to enforce
during an outage. Reading `entitlements(org)` refreshes it too.

## Entitlement cache and invalidation

`entitlements(org)` and `features(org)` are read from the cache while they are younger than
`entitlements_ttl` seconds, and from Billing otherwise. Every entitlements answer Billing gives,
including the one a refill reads, fills the cache and the last known allowance. If Billing is
unreachable and something is cached, the cached answer is returned marked `stale`, and the next
call asks again. A refusal is never hidden.

A feature is on only when Billing says it is enabled and the account is not suspended.
`featureValue` is the config value, `true` for a boolean feature, and null otherwise.

`invalidate(org)` makes the next read ask Billing and the next refill read the entitlements again.
It keeps the old answer, but only as the fallback for an outage.

`handleWebhook(event)` invalidates, and returns true, for `entitlements.changed`, every
`subscription.*`, `customer.suspended`, `customer.reactivated`, `customer.deleted`,
`customer.merged`, `bundle.benefit_started`, `bundle.benefit_ended`, `agreement.accepted` and
`agreement.withdrawn`. It invalidates every string the event's `data` has under
`organization_ref`, `organization_id`, `cbox_org_id` and `organization`, so it does not matter which
of them your app passes as `org`. `plan.changed`, or one of those events that names no organization,
invalidates everyone. Other events return false. Verify the delivery first with the SDK's webhook
helper.

## Reading Billing's answers

Strictly, so a broken answer is an outage and never a silent zero:

- A lease needs an integer `granted` ≥ 0. `lease_id` and `expires_at` may be null.
- Entitlements need `meters` as an object (an empty JSON array is an empty set); each meter needs
  a boolean `enabled`. A missing `allowance` is unlimited, a missing `weight` is null and a missing
  `overage` is `block`.
- Features need `features` as an object (or an empty array) and `account.suspended` as a boolean;
  each feature a boolean `enabled` and a `type` of `boolean`, `config` or null.

Anything else, and a body that is not JSON, is `BillingUnavailable`.

## Stores

The store holds the slices, open reservations, the usage buffer, the last known allowance, the
grace counters, the breaker and the cache. It needs six operations: `get`, atomic `increment`
(which keeps the key's expiry), `add` (set if absent, with an expiry), `put`, `delete` (which says
whether the key existed, so closing a reservation is an atomic claim) and `keys(prefix)`.

- **The memory store** (default) is right for one process. Each process then has its own slices,
  and its own usage stream per (organization, meter).
- **The Redis store** shares all of it between processes and hosts. Billing accepts one writer per
  (organization, meter) usage stream, so **several processes that report the same meters must
  share one store.** It takes any Redis client through a function that runs one raw command.

## Defaults

| Setting | Default |
| --- | --- |
| `lease_size` | 100 |
| `refill_threshold` | 20 |
| `failure_policy` | `last_known` |
| `reservation_ttl` | 300 s |
| `grace_units` / `grace_window` / `grace_meters` | 100 / 3600 s / none |
| `breaker_cooldown` | 30 s |
| `snapshot_refresh` | 300 s |
| `entitlements_ttl` | 60 s |
| `lock_ttl` / `lock_wait` | 10 s / 5 s |
| `timeout` / `lease_timeout` | 5 s / 2 s |
| key prefix | `cbox-billing:` |
