# Conformance scenarios

The scenarios here pin down how every SDK's enforcement client behaves ([SPEC.md](SPEC.md)). Each
SDK's test suite has a small runner that reads every `scenarios/*.json` file, builds an
`EnforcementClient` against a scripted Billing, plays the steps and checks each expectation. An
SDK is conformant when it passes all of them. Add a scenario here, never in one SDK.

Runners look for the scenarios at `$CBOX_CONFORMANCE_DIR`, then at `sdks/conformance/scenarios`
in this repository, then (Go and PHP) at `conformance/scenarios` beside the SDK, where the release
mirrors carry a copy; they fail when they find none.

## A scenario

```json
{
  "name": "hot path",
  "description": "What it shows.",
  "config": { "lease_size": 100, "failure_policy": "last_known" },
  "billing": {
    "POST /leases": [{ "body": { "lease_id": "lease_1", "granted": 100, "expires_at": "2027-01-15T08:05:00+00:00" } }],
    "GET /entitlements/org_a": { "always": { "body": { "meters": {} } } }
  },
  "steps": [
    { "op": "reserve", "org": "org_a", "meter": "api", "estimate": 10, "save": "r1",
      "expect": { "result": { "backed_by_lease": true, "grace": false } } }
  ]
}
```

- **`config`** overrides the defaults in SPEC.md, in snake case. The runner always refills inline
  (no background top-up), so every request happens in a known order.
- **The clock** starts at `1800000000000` ms (2027-01-15T08:00:00Z) and moves only with `advance`.
  The client and its memory store both read it.
- **`billing`** scripts Billing per route, `METHOD path`. A list is answered in order, one entry
  per request; `{"always": response}` answers every request. A response is
  `{"status": 200, "body": …}` (status defaults to 200; a string body is sent as is, so it can be
  malformed) or `{"network_error": true}`. A request with nothing left to answer fails the
  scenario, and so does a list not used up by the end.

## Steps

Every step has an `op`. A step without `expect.error` must succeed.

| `op` | Fields | `expect.result` |
| --- | --- | --- |
| `reserve` | `org`, `meter`, `estimate`, `save` | `{backed_by_lease, grace}` |
| `reserve_many` | `org`, `meters` (`{meter: estimate}`), `save` | `{buckets: {meter: {backed_by_lease, grace}}}` |
| `commit` | `ref` (a saved reservation), `actual` (a number, or a map for a set) | — |
| `release` | `ref` | — |
| `can` | `org`, `meter`, `n` | boolean |
| `balance` | `org`, `meter` | number |
| `report` | `org` (optional) | number of organizations reported |
| `sweep` | — | number of reservations reclaimed |
| `entitlements` | `org` | `{stale, meters: {meter: {enabled, allowance, weight, overage}}}` |
| `features` | `org` | `{stale, suspended, features: {key: {type, enabled, value, source}}}` |
| `has_feature` | `org`, `key` | boolean |
| `feature_value` | `org`, `key` | the value, `true` for a boolean feature, or `null` |
| `cost` | `org`, `meter`, `units` | number |
| `invalidate` | `org` (optional) | — |
| `handle_webhook` | `event` (`{type, data}`) | boolean |
| `advance` | `seconds` | — |
| `respond` | `route`, `with` (a list of responses to add to that route's queue) | — |
| `expect_requests` | `requests`: `[{route, body?}]` | — |

`expect.error` is one of `QuotaExceeded`, `BillingRefused`, `BillingUnavailable` and
`InvalidArgument`; `expect.reason` checks `QuotaExceeded`'s reason and `expect.status`
`BillingRefused`'s status.

`expect_requests` checks every request Billing received since the previous `expect_requests` (or
the start), in order: its route, and its JSON body exactly when `body` is given. An empty list
says nothing was asked.

Results compare as JSON: numbers by value, objects by their keys.
