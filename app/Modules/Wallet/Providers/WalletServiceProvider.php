<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Providers;

use App\Modules\Wallet\Contracts\FxRateProvider;
use App\Modules\Wallet\Contracts\PayoutGateways;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Gateways\ConfiguredPayoutGateways;
use App\Modules\Wallet\Gateways\Fake\FakeFxRateProvider;
use App\Modules\Wallet\Gateways\Fake\FakePayoutGateway;
use App\Modules\Wallet\Gateways\Fake\FakeTopUpGateway;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Contracts\Foundation\Application;

final class WalletServiceProvider extends ModuleServiceProvider
{
    public const array TABLES = [
        'ledger_accounts',
        'ledger_transactions',
        'ledger_entries',
        'ledger_balances',
        'topups',
        'payouts',
        'gateway_webhooks',
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
    }
}
