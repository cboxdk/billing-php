<?php

declare(strict_types=1);

namespace Cbox\Billing;

use Cbox\Billing\Exception\WebhookVerificationException;

/**
 * Verify webhook deliveries from Cbox Billing.
 *
 * Every delivery carries `X-Cbox-Signature: t={unix timestamp},v1={hex HMAC-SHA256}`. The MAC is
 * computed with the endpoint's signing secret over `"{t}.{raw body}"`. Verify against the raw
 * request body, byte for byte (`file_get_contents('php://input')`, or `$request->getContent()`):
 * decoding and re-encoding the JSON changes it.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-Cbox-Signature';

    public const TIMESTAMP_HEADER = 'X-Cbox-Timestamp';

    public const EVENT_TYPE_HEADER = 'X-Cbox-Event-Type';

    public const DELIVERY_ID_HEADER = 'X-Cbox-Delivery-Id';

    /** Deliveries signed more than this many seconds from now are refused as replays by default. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verify a delivery, or throw. `$tolerance` null turns the replay check off.
     *
     * @throws WebhookVerificationException
     */
    public static function verify(string $payload, ?string $signature, string $secret, ?int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): void
    {
        if ($signature === null || $signature === '') {
            throw new WebhookVerificationException('Missing '.self::SIGNATURE_HEADER.' header.');
        }

        $parsed = self::parseSignatureHeader($signature);
        if ($parsed === null) {
            throw new WebhookVerificationException('Malformed '.self::SIGNATURE_HEADER.' header.');
        }
        [$timestamp, $candidates] = $parsed;

        if ($tolerance !== null && abs(($now ?? time()) - $timestamp) > $tolerance) {
            throw new WebhookVerificationException('The signature timestamp is outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return;
            }
        }

        throw new WebhookVerificationException('No signature matches the payload.');
    }

    /**
     * Verify a delivery and return its envelope: `id`, `type`, `data`, `delivery_id`, `created_at`.
     *
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationException
     */
    public static function constructEvent(string $payload, ?string $signature, string $secret, ?int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): array
    {
        self::verify($payload, $signature, $secret, $tolerance, $now);

        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($event)) {
            throw new WebhookVerificationException('The payload is not a JSON object.');
        }

        /** @var array<string, mixed> $event */
        return $event;
    }

    /**
     * Parse `t=…,v1=…` into the timestamp and every `v1` signature, or null when malformed.
     *
     * @return array{0: int, 1: list<string>}|null
     */
    public static function parseSignatureHeader(string $header): ?array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        return $timestamp === null || $signatures === [] ? null : [$timestamp, $signatures];
    }
}
