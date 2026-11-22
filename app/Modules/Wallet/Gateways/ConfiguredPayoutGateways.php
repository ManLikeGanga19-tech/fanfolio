<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Gateways;

use App\Modules\Wallet\Contracts\Data\PayoutRail;
use App\Modules\Wallet\Contracts\PayoutGateway;
use App\Modules\Wallet\Contracts\PayoutGateways;
use App\Support\Gateways\FakeDriver;
use App\Support\Gateways\GatewayDriverException;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves the driver for each payout rail from config fanfolio.drivers.payout.<rail>.
 * Drivers are registered by WalletServiceProvider; each is constructed with its rail.
 */
final class ConfiguredPayoutGateways implements PayoutGateways
{
    /** @var array<string, PayoutGateway> */
    private array $resolved = [];

    /**
     * @param  array<string, class-string<PayoutGateway>>  $drivers  driver name => class
     */
    public function __construct(
        private readonly Application $app,
        private readonly array $drivers,
    ) {}

    public function for(PayoutRail $rail): PayoutGateway
    {
        return $this->resolved[$rail->value] ??= $this->resolve($rail);
    }

    private function resolve(PayoutRail $rail): PayoutGateway
    {
        $configKey = 'payout.'.$rail->value;
        $driver = config('fanfolio.drivers.'.$configKey);

        if (! is_string($driver) || ! array_key_exists($driver, $this->drivers)) {
            throw GatewayDriverException::unknown(PayoutGateway::class, $configKey, is_string($driver) ? $driver : null);
        }

        $class = $this->drivers[$driver];

        if (is_subclass_of($class, FakeDriver::class) && $this->app->environment('production')) {
            throw GatewayDriverException::fakeInProduction(PayoutGateway::class.' ('.$rail->value.')');
        }

        $gateway = $this->app->make($class, ['rail' => $rail]);

        if (! $gateway instanceof PayoutGateway) {
            throw GatewayDriverException::unknown(PayoutGateway::class, $configKey, $driver);
        }

        return $gateway;
    }
}
