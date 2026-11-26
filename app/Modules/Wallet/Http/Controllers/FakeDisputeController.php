<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Controllers;

use App\Modules\Wallet\Actions\ReceiveGatewayWebhook;
use App\Modules\Wallet\Contracts\Data\DisputeNotice;
use App\Modules\Wallet\Contracts\Data\GatewayEventType;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Enums\DisputeStatus;
use App\Modules\Wallet\Enums\TopUpStatus;
use App\Modules\Wallet\Gateways\Fake\FakeTopUpGateway;
use App\Modules\Wallet\Models\Dispute;
use App\Modules\Wallet\Models\TopUp;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Local and testing only (registered only then, with the fake gateway): the stand-in for
 * the processor's dispute desk. "File chargeback" and "The bank decides" send the same
 * signed webhooks a processor would, through the real webhook pipeline and the payments
 * queue, so the Disputes board can be tried in a browser.
 */
final class FakeDisputeController
{
    public function index(): View
    {
        return view('wallet::fake.disputes', [
            'topUps' => TopUp::query()->where('status', TopUpStatus::Paid)->latest()->limit(20)->get(),
            'disputes' => Dispute::query()->whereIn('status', DisputeStatus::UNDECIDED)->orderBy('respond_by')->get(),
        ]);
    }

    public function chargeback(Request $request, string $topUp, TopUpGateway $gateway, ReceiveGatewayWebhook $receive): RedirectResponse
    {
        $request->validate([
            'reason' => ['required', Rule::in(DisputeNotice::REASONS)],
            'three_ds' => ['required', Rule::in(DisputeNotice::THREE_DS)],
            'days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);

        $record = TopUp::query()->whereKey($topUp)->where('status', TopUpStatus::Paid)->firstOrFail();
        $notice = new DisputeNotice(
            'CB-'.random_int(1000, 99999),
            $request->string('reason')->toString(),
            CarbonImmutable::now()->addDays($request->integer('days')),
            $request->string('three_ds')->toString(),
        );

        $this->send($gateway, $receive, GatewayEventType::Chargeback, $record, $notice);

        return redirect()->route('fake.disputes')->with('status', 'Chargeback '.$notice->caseRef.' sent. It reaches the Disputes board once the payments queue has run.');
    }

    public function decide(Request $request, string $dispute, TopUpGateway $gateway, ReceiveGatewayWebhook $receive): RedirectResponse
    {
        $record = Dispute::query()->whereKey($dispute)->whereIn('status', DisputeStatus::UNDECIDED)->firstOrFail();
        $won = $request->string('outcome')->toString() === 'won';

        $this->send($gateway, $receive, $won ? GatewayEventType::DisputeWon : GatewayEventType::DisputeLost, TopUp::query()->findOrFail($record->topup_id), new DisputeNotice($record->gateway_case_ref));

        return redirect()->route('fake.disputes')->with('status', 'The bank decided '.$record->gateway_case_ref.($won ? ' for FanFolio.' : ' for the card holder.'));
    }

    private function send(TopUpGateway $gateway, ReceiveGatewayWebhook $receive, GatewayEventType $type, TopUp $topUp, DisputeNotice $notice): void
    {
        abort_unless($gateway instanceof FakeTopUpGateway, 404);

        $webhook = $gateway->signedWebhook($type, (string) $topUp->gateway_ref, Money::of($topUp->amount_minor, $topUp->currency), null, null, $topUp->payment_hint, $notice);
        $receive->handle($gateway->name(), $webhook['payload'], $webhook['headers']);
    }
}
