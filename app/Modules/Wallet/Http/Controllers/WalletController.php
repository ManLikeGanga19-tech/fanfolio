<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Http\Controllers;

use App\Modules\Identity\Contracts\SessionSecurity;
use App\Modules\Wallet\Actions\StartTopUp;
use App\Modules\Wallet\Contracts\Data\TopUpMethod;
use App\Modules\Wallet\Contracts\WalletDisplay;
use App\Modules\Wallet\Enums\TopUpStatus;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Models\TopUp;
use App\Modules\Wallet\Support\TopUpPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Canvas FanCore · Wallet & top-up and FanMore · Top-up success (M3 PR 2). Fan pages are
 * server-rendered Blade under the strict CSP (D-07).
 */
final class WalletController
{
    public function show(Request $request, WalletDisplay $display): View
    {
        $userId = self::user($request);
        $credits = $display->credits($userId);
        $currency = $display->currency($userId, $request->header('Accept-Language'));

        return view('wallet::wallet', [
            'credits' => $credits,
            'approximate' => $display->approximate($credits, $currency),
            'packs' => TopUpPack::all(),
            'methods' => TopUpPack::methods(),
            // D-20: a chargeback freezes the wallet until an admin lifts it.
            'frozen' => DB::table('wallet_freezes')->where('fan_id', $userId)->whereNull('lifted_at')->exists(),
            // One key per page view: a double tap or a resent form starts one top-up only.
            'idempotencyKey' => (string) Str::ulid(),
        ]);
    }

    public function startTopUp(Request $request, StartTopUp $start, SessionSecurity $security): RedirectResponse
    {
        $request->validate([
            'pack' => ['required', 'integer', 'min:0'],
            'method' => ['required', Rule::enum(TopUpMethod::class)],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ], [
            'pack.required' => 'Choose a top-up amount.',
            'method.required' => 'Choose how to pay.',
        ]);

        try {
            $topUp = $start->handle(
                self::user($request),
                $request->integer('pack'),
                TopUpMethod::from($request->string('method')->toString()),
                $request->header('Idempotency-Key') ?? $request->string('idempotency_key')->toString(),
                (string) $request->ip(),
                ! $security->isOnNewDevice($request->session(), self::user($request)),
            );
        } catch (WalletException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        // Off to the gateway's hosted checkout; a repeat of a paid top-up shows its result instead.
        return $topUp->status === TopUpStatus::Pending && $topUp->checkout_url !== null
            ? redirect()->away($topUp->checkout_url)
            : redirect()->route('wallet.topups.show', $topUp->id);
    }

    public function showTopUp(Request $request, string $topUp, WalletDisplay $display): View
    {
        $record = self::own($request, $topUp);

        return view('wallet::topup', [
            'topUp' => $record,
            'balance' => $display->credits($record->fan_id),
            'descriptor' => config()->string('fanfolio.brand.statement_descriptor'),
        ]);
    }

    /** Decision D-22: a printable receipt (the browser saves it as PDF). */
    public function receipt(Request $request, string $topUp): View
    {
        $record = self::own($request, $topUp);
        abort_unless($record->status === TopUpStatus::Paid, 404);

        return view('wallet::receipt', [
            'topUp' => $record,
            'descriptor' => config()->string('fanfolio.brand.statement_descriptor'),
        ]);
    }

    /** A fan sees only their own top-ups; anyone else gets "not found" (SEC-06, no IDOR). */
    public static function own(Request $request, string $topUpId): TopUp
    {
        $record = TopUp::query()->whereKey($topUpId)->where('fan_id', self::user($request))->first();
        abort_unless($record instanceof TopUp, 404);

        return $record;
    }

    public static function user(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();
        abort_unless(is_string($id), 403);

        return $id;
    }
}
