<?php

declare(strict_types=1);

use App\Modules\Wallet\Contracts\Data\ExchangeRate;
use App\Modules\Wallet\Contracts\Data\PayoutInstruction;
use App\Modules\Wallet\Contracts\Data\PayoutRail;
use App\Modules\Wallet\Contracts\Data\PayoutStatus;
use App\Modules\Wallet\Contracts\FxRateProvider;
use App\Modules\Wallet\Contracts\PayoutGateway;
use App\Modules\Wallet\Contracts\PayoutGateways;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Gateways\ConfiguredPayoutGateways;
use App\Modules\Wallet\Gateways\Fake\FakePayoutGateway;
use App\Support\Gateways\GatewayDriverException;
use App\Support\Money\Money;
use App\Support\Webhooks\HmacSignature;
use App\Support\Webhooks\InvalidWebhookSignature;

// M3 "Done when": 100% coverage on the Wallet module, including the M0 gateway fakes.

it('refuses an unknown payout driver, a fake one in production, and a class that is not a gateway', function (): void {
    $gateways = new ConfiguredPayoutGateways(app(), ['fake' => FakePayoutGateway::class, 'odd' => stdClass::class]);

    config(['fanfolio.drivers.payout.bank' => 'missing']);
    expect(fn (): PayoutGateway => $gateways->for(PayoutRail::Bank))->toThrow(GatewayDriverException::class);

    config(['fanfolio.drivers.payout.bank' => 'odd']);
    expect(fn (): PayoutGateway => $gateways->for(PayoutRail::Bank))->toThrow(GatewayDriverException::class);

    config(['fanfolio.drivers.payout.bank' => 'fake']);
    app()->detectEnvironment(fn (): string => 'production');
    try {
        expect(fn (): PayoutGateway => $gateways->for(PayoutRail::Bank))->toThrow(GatewayDriverException::class);
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }
});

it('reads back a fake payout by reference and refuses an unknown one', function (): void {
    $gateway = app(PayoutGateways::class)->for(PayoutRail::Usdt);
    $sent = $gateway->send(new PayoutInstruction('po-1', PayoutRail::Usdt, Money::of(5000, 'USD'), 'Test Person', ['address' => 'T123']));

    expect($gateway->rail())->toBe(PayoutRail::Usdt)
        ->and($gateway->status($sent->gatewayRef)->status)->toBe(PayoutStatus::Sent)
        ->and(fn () => $gateway->status('nope'))->toThrow(RuntimeException::class, 'Unknown fake payout');
});

it('gives the same currency at rate 1 and refuses a pair it has no rate for', function (): void {
    $fx = app(FxRateProvider::class);

    expect($fx->rate('USD', 'USD')->convert(Money::of(123, 'USD'))->equals(Money::of(123, 'USD')))->toBeTrue()
        ->and(fn () => $fx->rate('USD', 'XYZ'))->toThrow(RuntimeException::class, 'No fake FX rate');
});

it('converts only the currency a rate is for', function (): void {
    $rate = new ExchangeRate('USD', 'KES', 129_000_000, new DateTimeImmutable);

    expect(fn (): Money => $rate->convert(Money::of(1, 'KES')))->toThrow(InvalidArgumentException::class, 'Rate converts USD');
});

it('names itself and refuses signed payloads that are not well-formed events', function (string $payload, string $message): void {
    config(['fanfolio.fake.webhook_secret' => 'test-only-secret']);
    $gateway = app(TopUpGateway::class);
    $header = new HmacSignature('test-only-secret')->sign($payload, new DateTimeImmutable);

    expect($gateway->name())->toBe('fake')
        ->and(fn () => $gateway->parseWebhook($payload, ['x-fake-signature' => $header]))->toThrow(InvalidWebhookSignature::class, $message);
})->with([
    'not json' => ['{not json', 'not valid JSON'],
    'missing fields' => ['{"id":"evt_1"}', 'missing required fields'],
    'unknown type' => ['{"id":"evt_1","type":"teleport","ref":"r","amount_minor":1,"currency":"USD","occurred_at":"2026-11-24T10:00:00+00:00"}', 'invalid type or timestamp'],
]);
