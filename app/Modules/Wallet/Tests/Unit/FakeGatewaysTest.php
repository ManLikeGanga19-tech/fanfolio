<?php

declare(strict_types=1);

use App\Modules\Wallet\Contracts\Data\CheckoutRequest;
use App\Modules\Wallet\Contracts\Data\GatewayEventType;
use App\Modules\Wallet\Contracts\Data\PayoutInstruction;
use App\Modules\Wallet\Contracts\Data\PayoutRail;
use App\Modules\Wallet\Contracts\Data\PayoutStatus;
use App\Modules\Wallet\Contracts\Data\TopUpMethod;
use App\Modules\Wallet\Contracts\FxRateProvider;
use App\Modules\Wallet\Contracts\PayoutGateways;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Gateways\Fake\FakePayoutGateway;
use App\Modules\Wallet\Gateways\Fake\FakeTopUpGateway;
use App\Support\Money\Money;
use App\Support\Webhooks\InvalidWebhookSignature;

it('returns a hosted checkout URL, never collecting card data itself', function (): void {
    $session = app(TopUpGateway::class)->createCheckout(new CheckoutRequest(
        '01JTOPUP000000000000000000', '01JFAN0000000000000000000', Money::of(1000, 'USD'), TopUpMethod::Card, 'https://app.test/ok', 'https://app.test/cancel',
    ));

    expect($session->gatewayRef)->toStartWith('fake_co_')
        ->and($session->redirectUrl)->toContain('/_fake/checkout/'.$session->gatewayRef);
});

it('round-trips a signed webhook', function (): void {
    /** @var FakeTopUpGateway $gateway */
    $gateway = app(TopUpGateway::class);
    $webhook = $gateway->signedWebhook(GatewayEventType::PaymentSucceeded, 'fake_co_1', Money::of(2500, 'USD'), 'evt_123');

    $event = $gateway->parseWebhook($webhook['payload'], $webhook['headers']);

    expect($event->eventId)->toBe('evt_123')
        ->and($event->type)->toBe(GatewayEventType::PaymentSucceeded)
        ->and($event->amount->equals(Money::of(2500, 'USD')))->toBeTrue();
});

it('rejects a webhook whose amount was changed in transit', function (): void {
    /** @var FakeTopUpGateway $gateway */
    $gateway = app(TopUpGateway::class);
    $webhook = $gateway->signedWebhook(GatewayEventType::PaymentSucceeded, 'fake_co_1', Money::of(500, 'USD'));

    $gateway->parseWebhook(str_replace('"amount_minor":500', '"amount_minor":50000', $webhook['payload']), $webhook['headers']);
})->throws(InvalidWebhookSignature::class);

it('rejects a replayed webhook older than five minutes', function (): void {
    /** @var FakeTopUpGateway $gateway */
    $gateway = app(TopUpGateway::class);
    $webhook = $gateway->signedWebhook(GatewayEventType::PaymentSucceeded, 'fake_co_1', Money::of(500, 'USD'), at: new DateTimeImmutable('-10 minutes'));

    $gateway->parseWebhook($webhook['payload'], $webhook['headers']);
})->throws(InvalidWebhookSignature::class);

it('rejects an unsigned webhook', function (): void {
    app(TopUpGateway::class)->parseWebhook('{"id":"evt_1"}', []);
})->throws(InvalidWebhookSignature::class);

it('sends a payout once per payout id, however often it is retried', function (): void {
    /** @var FakePayoutGateway $bank */
    $bank = app(PayoutGateways::class)->for(PayoutRail::Bank);
    $instruction = new PayoutInstruction('01JPAYOUT00000000000000000', PayoutRail::Bank, Money::of(90000, 'USD'), 'TEST PERSON', ['account' => '000000']);

    $first = $bank->send($instruction);
    $bank->willReturn(PayoutStatus::Failed, 'should not apply to a retry');
    $retry = $bank->send($instruction);

    expect($retry->status)->toBe(PayoutStatus::Sent)
        ->and($retry->gatewayRef)->toBe($first->gatewayRef)
        ->and($bank->status($first->gatewayRef)->status)->toBe(PayoutStatus::Sent);
});

it('can script a failed payout', function (): void {
    /** @var FakePayoutGateway $paxum */
    $paxum = app(PayoutGateways::class)->for(PayoutRail::Paxum);
    $paxum->willReturn(PayoutStatus::Failed, 'account_closed');

    $result = $paxum->send(new PayoutInstruction('01JPAYOUT00000000000000001', PayoutRail::Paxum, Money::of(60000, 'USD'), 'TEST PERSON', ['email' => 'payee@example.test']));

    expect($result->status)->toBe(PayoutStatus::Failed)
        ->and($result->failureReason)->toBe('account_closed');
});

it('refuses an instruction for a different rail', function (): void {
    app(PayoutGateways::class)->for(PayoutRail::Usdt)
        ->send(new PayoutInstruction('01JPAYOUT00000000000000002', PayoutRail::Bank, Money::of(100, 'USD'), 'TEST PERSON', []));
})->throws(InvalidArgumentException::class);

it('converts credits to a local-currency estimate without floats', function (): void {
    $kes = app(FxRateProvider::class)->rate('USD', 'KES')->convert(Money::credits(250));

    expect($kes->currency)->toBe('KES')
        ->and($kes->amountMinor)->toBe(32500);
});
