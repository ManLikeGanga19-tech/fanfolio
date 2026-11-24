<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Providers;

use App\Modules\Wallet\Actions\LedgerService;
use App\Modules\Wallet\Actions\WalletDisplayService;
use App\Modules\Wallet\Console\CreateLedgerPartitions;
use App\Modules\Wallet\Console\VerifyLedger;
use App\Modules\Wallet\Contracts\FxRateProvider;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\PayoutGateways;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Contracts\WalletDisplay;
use App\Modules\Wallet\Gateways\ConfiguredPayoutGateways;
use App\Modules\Wallet\Gateways\Fake\FakeFxRateProvider;
use App\Modules\Wallet\Gateways\Fake\FakePayoutGateway;
use App\Modules\Wallet\Gateways\Fake\FakeTopUpGateway;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class WalletServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'ledger_accounts',
        'ledger_transactions',
        'ledger_entries',
        'ledger_entries_default',
        'ledger_balances',
        'topups',
        'payouts',
        'gateway_webhooks',
        'wallet_freezes',
        'wallet_preferences',
    ];

    public static function moduleName(): string
    {
        return 'Wallet';
    }

    public function register(): void
    {
        $this->bindDriver(TopUpGateway::class, 'topup', ['fake' => FakeTopUpGateway::class]);
        $this->bindDriver(FxRateProvider::class, 'fx', ['fake' => FakeFxRateProvider::class]);
        $this->app->singleton(PayoutGateways::class, static fn (Application $app): PayoutGateways => new ConfiguredPayoutGateways($app, [
            'fake' => FakePayoutGateway::class,
        ]));
        $this->app->singleton(Ledger::class, LedgerService::class);
        $this->app->bind(WalletDisplay::class, WalletDisplayService::class);
    }

    public function boot(): void
    {
        parent::boot();

        // SEC-14: starting top-ups per account; webhooks per sending address.
        RateLimiter::for('topups', static function (Request $request): Limit {
            $id = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(10)->by('topups:'.(is_string($id) ? $id : (string) $request->ip()));
        });
        RateLimiter::for('webhooks', static fn (Request $request): Limit => Limit::perMinute(600)->by('webhooks:'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([VerifyLedger::class, CreateLedgerPartitions::class]);
        }

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('wallet:ledger-partitions')->dailyAt('01:00')->onOneServer()->withoutOverlapping();
            $schedule->command('wallet:verify-ledger')->dailyAt('02:30')->onOneServer()->withoutOverlapping();
        });
    }
}
