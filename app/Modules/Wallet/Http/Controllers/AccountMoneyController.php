<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Controllers;

use App\Modules\Wallet\Actions\SetDisplayCurrency;
use App\Modules\Wallet\Contracts\WalletDisplay;
use App\Modules\Wallet\Enums\TopUpStatus;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Models\TopUp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Account pages the Wallet module serves: canvas FanProfile · Purchases & receipts and
 * the Currency setting from the Account hub (D-21). Subscriptions, unlocks and tips join
 * the purchases list as M5 and M6 post them.
 */
final class AccountMoneyController
{
    public const array TABS = ['all' => 'All', 'topup' => 'Top-ups', 'subscription' => 'Subscriptions', 'unlock' => 'Unlocks', 'tip' => 'Tips'];

    public function purchases(Request $request): View
    {
        $userId = WalletController::user($request);
        $tab = array_key_exists($request->string('tab')->toString(), self::TABS) ? $request->string('tab')->toString() : 'all';

        $topUps = TopUp::query()
            ->where('fan_id', $userId)
            ->where('status', TopUpStatus::Paid)
            // Only top-ups exist until M5 and M6: the other tabs are empty for now.
            ->when(! in_array($tab, ['all', 'topup'], true), static fn ($q) => $q->whereRaw('false'))
            ->latest('paid_at')
            ->limit(100)
            ->get();

        return view('wallet::purchases', [
            'tabs' => self::TABS,
            'active' => $tab,
            'months' => $topUps->groupBy(static fn (TopUp $t): string => (string) $t->paid_at?->format('F Y')),
            'support' => config()->string('fanfolio.brand.support_email'),
        ]);
    }

    /** Decision D-22: a printable statement of the purchases list. */
    public function statement(Request $request): View
    {
        $userId = WalletController::user($request);

        return view('wallet::statement', [
            'topUps' => TopUp::query()->where('fan_id', $userId)->where('status', TopUpStatus::Paid)->latest('paid_at')->limit(500)->get(),
            'descriptor' => config()->string('fanfolio.brand.statement_descriptor'),
        ]);
    }

    public function currency(Request $request, WalletDisplay $display): View
    {
        return view('wallet::currency', [
            'current' => $display->currency(WalletController::user($request), $request->header('Accept-Language')),
            'options' => ['KES' => 'Kenyan shilling (KSh)', 'USD' => 'US dollar ($)'],
        ]);
    }

    public function saveCurrency(Request $request, SetDisplayCurrency $set): RedirectResponse
    {
        try {
            $set->handle(WalletController::user($request), $request->string('currency')->toString());
        } catch (WalletException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('account')->with('status', 'Currency saved.');
    }
}
