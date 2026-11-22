<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use DateTimeInterface;

/**
 * Timestamped HMAC-SHA256 signatures for inbound webhooks (SEC-13).
 *
 * Header format: "t=<unix seconds>,v1=<hex hmac of "<t>.<payload>">".
 * Signatures older or newer than the tolerance are rejected, so a captured
 * request cannot be replayed later. Duplicate event ids are rejected separately,
 * by the event-id dedup when the webhook is stored.
 */
final readonly class HmacSignature
{
    public const int DEFAULT_TOLERANCE_SECONDS = 300;

    public function __construct(
        private string $secret,
        private int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ) {
        if ($secret === '') {
            throw new InvalidWebhookSignature('Webhook secret is not configured.');
        }
    }

    public function sign(string $payload, DateTimeInterface $at): string
    {
        $timestamp = $at->getTimestamp();

        return sprintf('t=%d,v1=%s', $timestamp, $this->hmac($timestamp, $payload));
    }

    /**
     * @throws InvalidWebhookSignature
     */
    public function verify(string $payload, string $header, DateTimeInterface $now): void
    {
        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            throw new InvalidWebhookSignature('Malformed signature header.');
        }

        if (abs($now->getTimestamp() - (int) $timestamp) > $this->toleranceSeconds) {
            throw new InvalidWebhookSignature('Signature timestamp is outside the allowed window.');
        }

        if (! hash_equals($this->hmac((int) $timestamp, $payload), $signature)) {
            throw new InvalidWebhookSignature('Signature does not match.');
        }
    }

    private function hmac(int $timestamp, string $payload): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);
    }
}
