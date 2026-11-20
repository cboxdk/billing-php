<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests;

use Cbox\Billing\Exception\WebhookVerificationException;
use Cbox\Billing\Paginator;
use Cbox\Billing\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fixture is a delivery signed by the app's own WebhookDeliverer
 * (sdks/fixtures/generate-webhook-fixture.php), so this proves the helper accepts what the server
 * really sends, and refuses anything else.
 */
final class WebhookTest extends TestCase
{
    /** @return array{secret: string, timestamp: int, body: string, headers: array<string, string>} */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../fixtures/webhook.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_verifies_a_delivery_signed_by_the_app(): void
    {
        $f = self::fixture();
        $signature = $f['headers'][Webhook::SIGNATURE_HEADER];

        Webhook::verify($f['body'], $signature, $f['secret'], now: $f['timestamp']);
        $event = Webhook::constructEvent($f['body'], $signature, $f['secret'], now: $f['timestamp']);

        $this->assertSame($f['headers']['X-Cbox-Event-Type'], $event['type']);
        $this->assertSame($f['headers']['X-Cbox-Delivery-Id'], $event['delivery_id']);
    }

    /** @return array<string, array{0: callable(array<string, mixed>): array{0: string, 1: string}}> */
    public static function forgeries(): array
    {
        return [
            'tampered body' => [fn (array $f) => [$f['body'].' ', $f['secret']]],
            're-encoded body' => [fn (array $f) => [json_encode(json_decode($f['body'], true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $f['secret']]],
            'wrong secret' => [fn (array $f) => [$f['body'], 'whsec_wrong']],
        ];
    }

    #[DataProvider('forgeries')]
    public function test_refuses_a_tampered_body_a_re_encoded_body_and_a_wrong_secret(callable $forge): void
    {
        $f = self::fixture();
        [$payload, $secret] = $forge($f);
        $this->assertTrue($payload !== $f['body'] || $secret !== $f['secret']);

        $this->expectException(WebhookVerificationException::class);
        Webhook::verify($payload, $f['headers'][Webhook::SIGNATURE_HEADER], $secret, now: $f['timestamp']);
    }

    public function test_refuses_a_delivery_outside_the_tolerance_window_unless_the_check_is_off(): void
    {
        $f = self::fixture();
        $signature = $f['headers'][Webhook::SIGNATURE_HEADER];

        Webhook::verify($f['body'], $signature, $f['secret'], tolerance: null, now: $f['timestamp'] + 301);

        $this->expectException(WebhookVerificationException::class);
        Webhook::verify($f['body'], $signature, $f['secret'], now: $f['timestamp'] + 301);
    }

    /** @return array<string, array{0: string|null}> */
    public static function malformedHeaders(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'no timestamp' => ['v1=abc'],
            'no signature' => ['t=1793723794'],
            'non-numeric timestamp' => ['t=soon,v1='.str_repeat('0', 64)],
        ];
    }

    #[DataProvider('malformedHeaders')]
    public function test_refuses_a_missing_or_malformed_header(?string $header): void
    {
        $f = self::fixture();

        $this->expectException(WebhookVerificationException::class);
        Webhook::verify($f['body'], $header, $f['secret'], now: $f['timestamp']);
    }

    public function test_paginate_stops_on_the_last_page(): void
    {
        $page = fn (bool $more, ?string $next, array $data) => new class($more, $next, $data)
        {
            public function __construct(private bool $more, private ?string $next, private array $data) {}

            public function getHasMore(): bool
            {
                return $this->more;
            }

            public function getNextCursor(): ?string
            {
                return $this->next;
            }

            public function getData(): array
            {
                return $this->data;
            }
        };
        $pages = [$page(true, 'c1', [1, 2]), $page(false, null, [3])];
        $cursors = [];

        $items = iterator_to_array(Paginator::items(function (?string $cursor) use ($pages, &$cursors) {
            $cursors[] = $cursor;

            return $pages[count($cursors) - 1];
        }), false);

        $this->assertSame([1, 2, 3], $items);
        $this->assertSame([null, 'c1'], $cursors);
    }
}
