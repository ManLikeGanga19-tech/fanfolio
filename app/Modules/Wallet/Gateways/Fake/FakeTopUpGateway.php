<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Gateways\Fake;

use App\Modules\Wallet\Contracts\Data\CheckoutRequest;
use App\Modules\Wallet\Contracts\Data\CheckoutSession;
use App\Modules\Wallet\Contracts\Data\DisputeNotice;
use App\Modules\Wallet\Contracts\Data\GatewayEvent;
use App\Modules\Wallet\Contracts\Data\GatewayEventType;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Support\Gateways\FakeDriver;
use App\Support\Money\Money;
use App\Support\Webhooks\HmacSignature;
use App\Support\Webhooks\InvalidWebhookSignature;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;
use JsonException;

/**
 * Local and test stand-in for the top-up processor.
 *
 * Webhooks use the same signed, timestamped format a real driver must verify, so the
 * webhook pipeline (verify, store raw, dedupe, queue) is exercised end to end in tests.
 */
final readonly class FakeTopUpGateway implements FakeDriver, TopUpGateway
{
    public const string SIGNATURE_HEADER = 'x-fake-signature';

    public function __construct(private Config $config) {}

    public function name(): string
    {
        return 'fake';
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $ref = 'fake_co_'.Str::ulid()->toBase32();
        $appUrl = $this->config->get('app.url');

        return new CheckoutSession($ref, rtrim(is_string($appUrl) ? $appUrl : '', '/').'/_fake/checkout/'.$ref);
    }

    /**
     * Build a signed webhook as the processor would send it.
     *
     * @return array{payload: string, headers: array<string, string>}
     */
    public function signedWebhook(GatewayEventType $type, string $gatewayRef, Money $amount, ?string $eventId = null, ?DateTimeImmutable $at = null, ?string $paymentHint = 'Visa ····4242', ?DisputeNotice $dispute = null): array
    {
        $at ??= new DateTimeImmutable;
        $payload = json_encode([
            'id' => $eventId ?? 'evt_'.Str::ulid()->toBase32(),
            'type' => $type->value,
            'ref' => $gatewayRef,
            'amount_minor' => $amount->amountMinor,
            'currency' => $amount->currency,
            'occurred_at' => $at->format(DATE_ATOM),
            'payment_hint' => $paymentHint,
            'dispute' => $dispute instanceof DisputeNotice ? ['case_ref' => $dispute->caseRef] + $dispute->details() : null,
        ], JSON_THROW_ON_ERROR);

        return [
            'payload' => $payload,
            'headers' => [self::SIGNATURE_HEADER => $this->signer()->sign($payload, $at)],
        ];
    }

    public function parseWebhook(string $payload, array $headers): GatewayEvent
    {
        $this->signer()->verify($payload, $headers[self::SIGNATURE_HEADER] ?? '', new DateTimeImmutable);

        try {
            $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidWebhookSignature('Payload is not valid JSON.');
        }

        if (! is_array($data)
            || ! is_string($data['id'] ?? null)
            || ! is_string($data['type'] ?? null)
            || ! is_string($data['ref'] ?? null)
            || ! is_int($data['amount_minor'] ?? null)
            || ! is_string($data['currency'] ?? null)
            || ! is_string($data['occurred_at'] ?? null)) {
            throw new InvalidWebhookSignature('Payload is missing required fields.');
        }

        $type = GatewayEventType::tryFrom($data['type']);
        $occurredAt = DateTimeImmutable::createFromFormat(DATE_ATOM, $data['occurred_at']);

        if ($type === null || $occurredAt === false) {
            throw new InvalidWebhookSignature('Payload has an invalid type or timestamp.');
        }

        $hint = $data['payment_hint'] ?? null;

        return new GatewayEvent($this->name(), $data['id'], $type, $data['ref'], Money::of($data['amount_minor'], $data['currency']), $occurredAt, is_string($hint) ? $hint : null, $this->dispute($data['dispute'] ?? null));
    }

    /** The dispute block of a chargeback, won or lost event; null when absent or malformed. */
    private function dispute(mixed $data): ?DisputeNotice
    {
        if (! is_array($data) || ! is_string($data['case_ref'] ?? null) || $data['case_ref'] === '') {
            return null;
        }

        $respondBy = is_string($data['respond_by'] ?? null) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $data['respond_by']) : false;

        return new DisputeNotice(
            mb_substr($data['case_ref'], 0, 100),
            is_string($data['reason'] ?? null) ? $data['reason'] : 'other',
            $respondBy === false ? null : $respondBy,
            is_string($data['three_ds'] ?? null) ? $data['three_ds'] : null,
        );
    }

    private function signer(): HmacSignature
    {
        $secret = $this->config->get('fanfolio.fake.webhook_secret');
        $appKey = $this->config->get('app.key');

        if (! is_string($secret) || $secret === '') {
            $secret = hash_hmac('sha256', 'fanfolio-fake-webhook', is_string($appKey) ? $appKey : '');
        }

        return new HmacSignature($secret);
    }
}
