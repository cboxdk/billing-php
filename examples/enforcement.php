<?php

/*
 * Meters work against Cbox Billing with the enforcement client: a feature gate, a
 * reserve → work → commit cycle per request, a refusal handled as the customer's limit, and the
 * report and sweep you would schedule.
 *
 *   CBOX_BILLING_URL=https://api.cboxbilling.com/v1 CBOX_BILLING_TOKEN=cbt_… CBOX_BILLING_ORG=org_acme \
 *     php examples/enforcement.php
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Cbox\Billing\Enforcement\EnforcementClient;
use Cbox\Billing\Enforcement\QuotaExceededException;

$org = (string) getenv('CBOX_BILLING_ORG');

// PHP-FPM serves each request in a fresh process, so keep the state where every worker sees it:
// RedisStore::phpredis($redis), RedisStore::predis($client), or new ApcuStore on one host. The
// memory store (the default) is right for a long-running worker or a script like this one.
$billing = new EnforcementClient(
    token: (string) getenv('CBOX_BILLING_TOKEN'),
    baseUrl: (string) getenv('CBOX_BILLING_URL'),
);

// One request: hold an estimate, do the work, commit what it really used. The release in
// `finally` gives the hold back if the work throws; after a commit it does nothing.
$handle = static function () use ($billing, $org): int {
    $reservation = $billing->reserve($org, 'api.requests', 10); // QuotaExceededException: answer 429
    try {
        usleep(10_000); // the work
        $used = 4;
        $billing->commit($reservation, $used);

        return $used;
    } finally {
        $billing->release($reservation);
    }
};

// A boolean feature, and a config value. Both are cached and deny by default.
printf("sso: %s, max_projects: %s\n", var_export($billing->hasFeature($org, 'sso'), true), var_export($billing->featureValue($org, 'max_projects'), true));

for ($i = 1; $i <= 3; $i++) {
    printf("request %d used %d api.requests\n", $i, $handle());
}

// A meter capped at 100 refuses a larger reservation: the customer's limit, not an error in your app.
try {
    $billing->reserve($org, 'storage.gb', 150);
} catch (QuotaExceededException $refused) {
    printf("storage.gb refused: %s\n", $refused->reason);
}

// Schedule these: report() every few seconds, sweep() every minute (a cron job or a worker loop).
printf("reported %d organization(s), swept %d reservation(s)\n", $billing->report(), $billing->sweep());
