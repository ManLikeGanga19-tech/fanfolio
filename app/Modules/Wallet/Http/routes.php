<?php

declare(strict_types=1);

use App\Modules\Wallet\Filament\Studio\EarningsPage;
use App\Modules\Wallet\Http\Controllers\AccountMoneyController;
use App\Modules\Wallet\Http\Controllers\FakeCheckoutController;
use App\Modules\Wallet\Http\Controllers\FakeDisputeController;
use App\Modules\Wallet\Http\Controllers\WalletController;
use App\Modules\Wallet\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Canvas FanCore · Wallet & top-up, FanMore · Top-up success, FanProfile · Purchases & receipts (M3 PR 2).
Route::middleware('auth')->group(function (): void {
    Route::get('/wallet', [WalletController::class, 'show'])->name('wallet');
    Route::post('/wallet/topups', [WalletController::class, 'startTopUp'])->middleware('throttle:topups')->name('wallet.topups.store');
    Route::get('/wallet/topups/{topUp}', [WalletController::class, 'showTopUp'])->name('wallet.topups.show');
    Route::get('/wallet/topups/{topUp}/receipt', [WalletController::class, 'receipt'])->name('wallet.topups.receipt');

    Route::get('/account/purchases', [AccountMoneyController::class, 'purchases'])->name('account.purchases');
    Route::get('/account/purchases/statement', [AccountMoneyController::class, 'statement'])->name('account.purchases.statement');
    Route::get('/account/currency', [AccountMoneyController::class, 'currency'])->name('account.currency');
    Route::post('/account/currency', [AccountMoneyController::class, 'saveCurrency']);
});

// Decision D-13: a withdrawal needs a passkey or authenticator code confirmed just before.
// Studio sends the creator here; the step-up middleware has them confirm, then they return.
Route::middleware(['auth', 'step-up:second-factor'])->get('/wallet/payouts/confirm', static fn () => redirect()
    ->to(EarningsPage::getUrl(panel: 'studio'))
    ->with('status', 'Confirmed.'))->name('wallet.payouts.confirm');

// Spec "Inbound webhooks": signed by the gateway; no session or CSRF (see bootstrap/app.php).
Route::post('/webhooks/{gateway}', WebhookController::class)->middleware('throttle:webhooks')->name('webhooks.gateway');

// Local and testing only: the stand-in for the processor's hosted checkout.
if (app()->environment('local', 'testing') && config('fanfolio.drivers.topup') === 'fake') {
    Route::middleware('auth')->group(function (): void {
        Route::get('/_fake/checkout/{ref}', [FakeCheckoutController::class, 'show'])->name('fake.checkout');
        Route::post('/_fake/checkout/{ref}', [FakeCheckoutController::class, 'complete']);
        // The processor's dispute desk: file a chargeback, then the bank's decision.
        Route::get('/_fake/disputes', [FakeDisputeController::class, 'index'])->name('fake.disputes');
        Route::post('/_fake/disputes/topups/{topUp}', [FakeDisputeController::class, 'chargeback'])->name('fake.disputes.chargeback');
        Route::post('/_fake/disputes/{dispute}', [FakeDisputeController::class, 'decide'])->name('fake.disputes.decide');
    });
}
