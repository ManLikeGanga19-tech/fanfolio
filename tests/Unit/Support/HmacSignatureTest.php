<?php

declare(strict_types=1);

use App\Support\Webhooks\HmacSignature;
use App\Support\Webhooks\InvalidWebhookSignature;

// SEC-13: webhooks verified by signature and timestamp; replays rejected.

beforeEach(function (): void {
    $this->signer = new HmacSignature('test-secret-not-real');
    $this->at = new DateTimeImmutable('2026-11-22 12:00:00');
});

it('accepts a fresh, correctly signed payload', function (): void {
    $header = $this->signer->sign('{"id":"evt_1"}', $this->at);

    $this->signer->verify('{"id":"evt_1"}', $header, $this->at->modify('+30 seconds'));
})->throwsNoExceptions();

it('rejects a tampered payload', function (): void {
    $header = $this->signer->sign('{"amount":500}', $this->at);

    $this->signer->verify('{"amount":50000}', $header, $this->at);
})->throws(InvalidWebhookSignature::class, 'does not match');

it('rejects a replay older than five minutes', function (): void {
    $header = $this->signer->sign('{"id":"evt_1"}', $this->at);

    $this->signer->verify('{"id":"evt_1"}', $header, $this->at->modify('+301 seconds'));
})->throws(InvalidWebhookSignature::class, 'outside the allowed window');

it('rejects a signature made with another secret', function (): void {
    $header = new HmacSignature('other-secret')->sign('{}', $this->at);

    $this->signer->verify('{}', $header, $this->at);
})->throws(InvalidWebhookSignature::class);

it('rejects malformed headers', function (string $header): void {
    $this->signer->verify('{}', $header, $this->at);
})->with(['', 'garbage', 't=abc,v1=123', 't=1700000000'])->throws(InvalidWebhookSignature::class);

it('refuses an empty secret', function (): void {
    new HmacSignature('');
})->throws(InvalidWebhookSignature::class);
