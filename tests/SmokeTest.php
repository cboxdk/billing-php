<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests;

use Cbox\Billing\Api\InvoicesApi;
use Cbox\Billing\Api\PlansApi;
use Cbox\Billing\Api\SubscriptionsApi;
use Cbox\Billing\ApiException;
use Cbox\Billing\CboxBilling;
use Cbox\Billing\Configuration;
use Cbox\Billing\Exception\AuthenticationException;
use Cbox\Billing\Exception\CboxBillingException;
use Cbox\Billing\Exception\NotFoundException;
use Cbox\Billing\Idempotency;
use Cbox\Billing\Model\NewInvoice;
use Cbox\Billing\Model\NewInvoiceLine;
use Cbox\Billing\Paginator;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests against a Prism mock server started from the OpenAPI contract (sdks/test.sh starts
 * it). Prism checks every request against the spec and answers with the spec's examples.
 */
final class SmokeTest extends TestCase
{
    private static function baseUrl(): string
    {
        $url = getenv('CBOX_BILLING_MOCK_URL');

        return is_string($url) && $url !== '' ? $url : 'http://127.0.0.1:4010';
    }

    private function billing(array $headers = []): CboxBilling
    {
        return new CboxBilling('cbt_test_token', self::baseUrl(), $headers);
    }

    public function test_lists_plans_with_a_bearer_token_and_parses_the_models(): void
    {
        $plans = $this->billing()->api(PlansApi::class)->listPlans();

        $this->assertSame('EUR', $plans->getCurrency());
        $this->assertSame('pro', $plans->getData()[0]->getKey());
        $this->assertFalse($plans->getHasMore());
    }

    public function test_a_request_without_a_token_is_an_authentication_exception(): void
    {
        // The typed-error client with a configuration that has no token.
        $api = new PlansApi(CboxBilling::httpClient(), (new Configuration)->setHost(self::baseUrl()));

        try {
            $api->listPlans();
            $this->fail('Expected a 401.');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->getStatus());
            $this->assertInstanceOf(CboxBillingException::class, $e);
            $this->assertInstanceOf(ApiException::class, $e);
        }
    }

    public function test_an_error_body_becomes_a_typed_exception_carrying_error_and_message(): void
    {
        $api = $this->billing(['Prefer' => 'code=404'])->api(SubscriptionsApi::class);

        try {
            $api->getSubscription('org_acme');
            $this->fail('Expected a 404.');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->getStatus());
            $this->assertNotSame('', (string) $e->getError());
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_creates_an_invoice_with_an_idempotency_key(): void
    {
        $invoice = $this->billing()->api(InvoicesApi::class)->createInvoice(
            'org_acme',
            Idempotency::newKey(),
            new NewInvoice(['lines' => [
                new NewInvoiceLine(['description' => 'Consulting', 'quantity' => '2.000', 'unitAmountMinor' => 50000]),
            ]]),
        );

        $this->assertIsString($invoice->getNumber());
        $this->assertIsArray($invoice->getLines());
    }

    public function test_paginate_follows_next_cursor_across_pages(): void
    {
        $api = $this->billing()->api(InvoicesApi::class);
        $cursors = [];
        $seen = [];

        // The mock answers every page with `has_more: true`, so stop after three items.
        foreach (Paginator::items(function (?string $cursor) use ($api, &$cursors) {
            $cursors[] = $cursor;

            return $api->listInvoices('org_acme', cursor: $cursor);
        }) as $invoice) {
            $seen[] = $invoice->getId();
            if (count($seen) === 3) {
                break;
            }
        }

        $this->assertCount(3, $seen);
        $this->assertSame([null, 'eyJrIjo0Mn0', 'eyJrIjo0Mn0'], $cursors);
    }
}
