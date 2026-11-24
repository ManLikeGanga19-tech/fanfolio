<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Controllers;

use App\Modules\Wallet\Actions\ReceiveGatewayWebhook;
use App\Modules\Wallet\Contracts\Data\GatewayEventType;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Gateways\Fake\FakeTopUpGateway;
use App\Modules\Wallet\Models\TopUp;
use App\Support\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Local and testing only (routes registered only then, with the fake gateway): the
 * stand-in for the processor's hosted checkout. "Pay" or "Decline" sends the same signed
 * webhook a processor would, through the real webhook pipeline, then returns the fan.
 */
final class FakeCheckoutController
{
    public function show(Request $request, string $ref): View
    {
        return view('wallet::fake.checkout', ['topUp' => $this->topUp($request, $ref)]);
    }

    public function complete(Request $request, string $ref, TopUpGateway $gateway, ReceiveGatewayWebhook $receive): RedirectResponse
    {
        $topUp = $this->topUp($request, $ref);
        abort_unless($gateway instanceof FakeTopUpGateway, 404);

        $type = $request->string('outcome')->toString() === 'decline' ? GatewayEventType::PaymentFailed : GatewayEventType::PaymentSucceeded;
        $webhook = $gateway->signedWebhook($type, $ref, Money::of($topUp->amount_minor, $topUp->currency));

        $receive->handle($gateway->name(), $webhook['payload'], $webhook['headers']);

        return redirect()->route('wallet.topups.show', $topUp->id);
    }

    private function topUp(Request $request, string $ref): TopUp
    {
        $topUp = TopUp::query()->where('gateway_ref', $ref)->where('fan_id', WalletController::user($request))->first();
        abort_unless($topUp instanceof TopUp, 404);

        return $topUp;
    }
}
