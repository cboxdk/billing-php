<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests;

use Cbox\Billing\Enforcement\BillingRefusedException;
use Cbox\Billing\Enforcement\EnforcementClient;
use Cbox\Billing\Enforcement\QuotaExceededException;
use PHPUnit\Framework\TestCase;

/**
 * The enforcement client against a real Cbox Billing app in test mode.
 *
 * Runs when CBOX_BILLING_LIVE_URL (the API root, such as http://127.0.0.1:8000/api/v1) and
 * CBOX_BILLING_LIVE_TOKEN (a cbt_ token from sdks/testing/seed-live.php) are set; CI sets both.
 * The organization org_sdk_live_php is on the Team plan: api.requests is billed past 1,000,000,
 * storage.gb is capped at 100, sso is on, compliance is off and max_projects is 10.
 */
final class LiveTest extends TestCase
{
    private const ORG = 'org_sdk_live_php';

    private function client(?string $token = null): EnforcementClient
    {
        $url = (string) getenv('CBOX_BILLING_LIVE_URL');
        $envToken = (string) getenv('CBOX_BILLING_LIVE_TOKEN');
        if ($url === '' || $envToken === '') {
            $this->markTestSkipped('CBOX_BILLING_LIVE_URL and CBOX_BILLING_LIVE_TOKEN are not set.');
        }

        return new EnforcementClient(token: $token ?? $envToken, baseUrl: $url, deferRefill: false);
    }

    public function test_entitlements_and_features(): void
    {
        $billing = $this->client();
        $entitlements = $billing->entitlements(self::ORG);
        $this->assertTrue($entitlements->enabled('api.requests'));
        $this->assertSame(100, $entitlements->for('storage.gb')?->allowance);
        $this->assertSame('block', $entitlements->for('storage.gb')?->overage);
        $this->assertFalse($entitlements->stale);

        $this->assertTrue($billing->hasFeature(self::ORG, 'sso'));
        $this->assertFalse($billing->hasFeature(self::ORG, 'compliance'));
        $this->assertSame(10, $billing->featureValue(self::ORG, 'max_projects'));
    }

    public function test_reserve_commit_and_report(): void
    {
        $billing = $this->client();
        $reservation = $billing->reserve(self::ORG, 'api.requests', 5);
        $this->assertTrue($reservation->backedByLease());
        $this->assertSame(95, $billing->balance(self::ORG, 'api.requests'));
        $billing->commit($reservation, 3);
        $this->assertSame(97, $billing->balance(self::ORG, 'api.requests'));

        $this->assertSame(1, $billing->report(self::ORG), 'Billing accepted the running total');
        $this->assertSame(0, $billing->report(self::ORG), 'an unchanged stream is not sent again');
    }

    public function test_a_capped_meter_refuses_past_its_allowance(): void
    {
        try {
            $this->client()->reserve(self::ORG, 'storage.gb', 150);
            $this->fail('A reservation past the cap was admitted.');
        } catch (QuotaExceededException $e) {
            $this->assertSame('quota_exhausted', $e->reason);
        }
    }

    public function test_a_bad_token_is_refused_not_an_outage(): void
    {
        try {
            $this->client('cbt_not_a_token')->reserve(self::ORG, 'api.requests', 1);
            $this->fail('A bad token was admitted.');
        } catch (BillingRefusedException $e) {
            $this->assertSame(401, $e->status);
        }
    }
}
