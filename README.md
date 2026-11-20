# Cbox Billing for PHP

`cboxdk/billing-php` is the PHP client for the Cbox Billing API, generated from its OpenAPI
contract. PHP 8.2+, on Guzzle 7.

Building a Laravel app that enforces usage locally? `cboxdk/laravel-billing-client` is made for
that. This package is the general client for the whole API.

## Install

The package is not on Packagist yet. Until it is, add this repository as a path or VCS source:

```json
{
    "repositories": [
        { "type": "path", "url": "/path/to/cbox-billing/sdks/php" }
    ],
    "require": { "cboxdk/billing-php": "*" }
}
```

Once published:

```bash
composer require cboxdk/billing-php
```

## Quickstart

```php
use Cbox\Billing\Api\PlansApi;
use Cbox\Billing\CboxBilling;

$billing = new CboxBilling(
    getenv('CBOX_BILLING_TOKEN'), // cbl_… (live) or cbt_… (test)
    'https://api.cboxbilling.com/v1',
);

$plans = $billing->api(PlansApi::class)->listPlans();
foreach ($plans->getData() as $plan) {
    echo $plan->getKey(), PHP_EOL;
}
```

`$billing->api(…)` builds any generated API class (`PlansApi`, `SubscriptionsApi`, `InvoicesApi`,
`OrganizationsApi`, …) with the shared client and configuration. To build one yourself, pass
`CboxBilling::httpClient()` and a `Configuration` to its constructor.

## Writes and idempotency keys

A write that honours `Idempotency-Key` takes it as the `$idempotencyKey` argument. Make one key per
business action and keep it with that action: a retry with the same key replays the first response
instead of doing the work twice.

```php
use Cbox\Billing\Api\InvoicesApi;
use Cbox\Billing\Idempotency;
use Cbox\Billing\Model\NewInvoice;
use Cbox\Billing\Model\NewInvoiceLine;

$key = Idempotency::newKey(); // store it with the order you are invoicing

$invoice = $billing->api(InvoicesApi::class)->createInvoice('org_acme', $key, new NewInvoice([
    'lines' => [new NewInvoiceLine(['description' => 'Consulting', 'quantity' => '2.000', 'unitAmountMinor' => 50000])],
]));
```

Model constructors take an array keyed by the camelCase property names (`unitAmountMinor`); the
getters and setters follow the same names.

## Retries and automatic keys

Every write (POST, PUT, PATCH, DELETE) without an `Idempotency-Key` gets a fresh UUIDv4 one, so
it is safe to retry. A 429, a 5xx or a network error is then tried again, up to 3 tries in all,
with exponential backoff and jitter, waiting as long as `Retry-After` says when the server sends
it (at most 20 seconds). Reads are retried too. A write without a key, because you turned the
automatic keys off, is never retried. Every try of one call sends the same key.

The automatic key protects a single call. To make a retry of a whole business action safe (after a crash, say), pass your own `$idempotencyKey` and keep it with that action.

```php
use Cbox\Billing\RetryPolicy;

$billing = new CboxBilling(
    $token,
    $baseUrl,
    retry: new RetryPolicy(maxAttempts: 5, baseDelay: 0.25, maxDelay: 10.0), // or RetryPolicy::off()
    autoIdempotencyKey: true, // the default
);
```

## Pagination

List endpoints return one page with `has_more` and `next_cursor`. `Paginator::items` walks all of
them, lazily:

```php
use Cbox\Billing\Paginator;

$invoices = $billing->api(InvoicesApi::class);
foreach (Paginator::items(fn (?string $cursor) => $invoices->listInvoices('org_acme', cursor: $cursor)) as $invoice) {
    echo $invoice->getNumber(), PHP_EOL;
}
```

## Errors

Every non-2xx response throws a `Cbox\Billing\Exception\CboxBillingException` subclass:
`BadRequestException` (400), `AuthenticationException` (401), `PermissionDeniedException` (403),
`NotFoundException` (404), `ConflictException` (409), `ValidationException` (422, with
`getErrors()` per field), `RateLimitException` (429, with `getRetryAfter()`) or `ServerException`
(5xx). Each has `getStatus()`, `getError()`, `getErrorCode()` and `getMessage()` from the body, and
is still the generated `Cbox\Billing\ApiException`.

```php
use Cbox\Billing\Api\SubscriptionsApi;
use Cbox\Billing\Exception\NotFoundException;
use Cbox\Billing\Exception\PermissionDeniedException;

try {
    $billing->api(SubscriptionsApi::class)->getSubscription('org_acme');
} catch (NotFoundException) {
    // no active subscription
} catch (PermissionDeniedException $e) {
    if ($e->getError() === 'insufficient_scope') {
        error_log('The token needs '.$e->getRequiredScope());
    }
    throw $e;
}
```

## Webhooks

Verify every delivery against the **raw** request body, before you decode it:

```php
use Cbox\Billing\Exception\WebhookVerificationException;
use Cbox\Billing\Webhook;

try {
    $event = Webhook::constructEvent(
        file_get_contents('php://input'), // in Laravel: $request->getContent()
        $_SERVER['HTTP_X_CBOX_SIGNATURE'] ?? null,
        getenv('CBOX_BILLING_WEBHOOK_SECRET'),
    );
} catch (WebhookVerificationException) {
    http_response_code(400);
    exit;
}
// $event['type'], $event['data']; dedupe on $event['id']
```

A delivery signed more than 300 seconds from now is refused as a replay; pass `tolerance:` to
change that, or `null` to turn the check off.

## Enforcement: metering on the hot path

`Cbox\Billing\Enforcement` meters usage against Billing without a network call per request. Reservations
take units from a slice of the organization's allowance leased into a local store; Billing is
asked only when a slice runs low, and committed usage is reported as running totals. It is the
same design as `cboxdk/laravel-billing-client` and behaves the same in all five SDKs: the shared
[conformance scenarios](../conformance) pin it down, and [SPEC.md](../conformance/SPEC.md) is the
full reference. Use a token with the `enforce` scope.

```php
use Cbox\Billing\Enforcement\EnforcementClient;

$billing = new EnforcementClient(token: getenv('CBOX_BILLING_TOKEN'), baseUrl: 'https://api.cboxbilling.com/v1');

$reservation = $billing->reserve($org, 'api.requests', 10); // hold an estimate
try {
    $used = doTheWork();
    $billing->commit($reservation, $used); // the rest goes back to the slice
} finally {
    $billing->release($reservation); // gives the hold back if the work threw; a no-op after commit
}
```

Several meters at once, all or nothing: `reserveMany($org, ['api.requests' => 1, 'tokens' => 2000])`,
committed with an array per meter. `can($org, $meter, $n)` asks without holding anything.

**Features and entitlements**, cached for 60 seconds and deny by default:

```php
if ($billing->hasFeature($org, 'sso')) { … }
$limit = $billing->featureValue($org, 'max_projects'); // int|string|bool|null
$meters = $billing->entitlements($org)->meters;        // $meters['api.requests']->allowance
```

**Schedule two calls**: `$billing->report()` every few seconds (sends the running totals; a failed
report is simply sent again, larger, next time) and `$billing->sweep()` every minute (reclaims
reservations whose request died before it committed). A cron job or a worker loop will do.

**Keep the cache fresh from webhooks.** After `Webhook::constructEvent(…)` has verified a
delivery: `$billing->handleWebhook($event)`. It invalidates on `entitlements.changed`,
`subscription.*` and the other events that change what a customer holds.

**Pick a store that outlives the request.** PHP-FPM starts every request with a fresh process, so
the default memory store only suits a long-running worker or a script. Under FPM:

```php
use Cbox\Billing\Enforcement\{ApcuStore, RedisStore};

$store = RedisStore::phpredis($redis);   // the phpredis extension's \Redis
$store = RedisStore::predis($client);    // a Predis\Client
$store = new ApcuStore;                  // one host only (needs apcu)
$billing = new EnforcementClient(token: $token, baseUrl: $baseUrl, store: $store);
```

Processes that report the same meters must share one store: Billing accepts one writer per
(organization, meter) usage stream, so use Redis as soon as you run more than one host. PHP has
no threads: a slice that runs low is topped up after the response is sent
(`fastcgi_finish_request`), or call `$billing->flushDeferred()` yourself in a worker.

**When Billing is down.** A refill that cannot reach Billing opens a breaker for 30 seconds,
and reservations go to the failure policy: `last_known` (the default) admits up to the allowance
it last knew, then a small outage grace (100 units per organization and meter per hour); `allow`
admits everything; `deny` refuses. Everything admitted is still committed and reported, and
Billing settles it once it is back. Entitlements and features are served from the cache, marked
`stale`, while it is unreachable.

| Error | When | Your answer |
| --- | --- | --- |
| `QuotaExceededException` | Billing granted nothing: the allowance is spent, the meter is off, a credit or overage cap is reached, or an agreement is not accepted (`reason`) | the customer's limit: a 429 or an upgrade prompt |
| `BillingRefusedException` | Billing refused the call (a 4xx such as a revoked token; `status`) | a bug or a config error: alert |
| `BillingUnavailableException` | Billing cannot be reached and the failure policy refuses | your choice: retry later, or 503 |

All three extend `EnforcementException`. An invalid argument (a negative estimate, a second
commit) throws `InvalidArgumentException`.

Named options (durations in seconds): `leaseSize`, `refillThreshold`, `failurePolicy`
(`FailurePolicy::LastKnown`, `Allow`, `Deny`), `reservationTtl`, `graceUnits`, `graceWindow`,
`graceMeters`, `breakerCooldown`, `entitlementsTtl`, `timeout`, `leaseTimeout`, `prefix`,
`deferRefill` and more; defaults are in SPEC.md. A runnable example:
[`examples/enforcement.php`](examples/enforcement.php).

## Development

`src/Api`, `src/Model` and the other top-level classes in `src/` are generated by
[`sdks/generate.sh`](../generate.sh). `src/CboxBilling.php`, `src/Exception/`,
`src/Idempotency.php`, `src/Paginator.php`, `src/Webhook.php`, `src/Enforcement/`, `tests/` and
`examples/` are written by hand. Tests: [`sdks/test.sh php`](../test.sh), or
[`sdks/live.sh php`](../live.sh) to add the live tests against the app.

## Licence

MIT
