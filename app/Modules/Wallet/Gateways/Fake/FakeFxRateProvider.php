<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Gateways\Fake;

use App\Modules\Wallet\Contracts\Data\ExchangeRate;
use App\Modules\Wallet\Contracts\FxRateProvider;
use App\Support\Gateways\FakeDriver;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

/**
 * Fixed rates from config fanfolio.fake.fx_rates ("FROM:TO" => micro-units).
 */
final readonly class FakeFxRateProvider implements FakeDriver, FxRateProvider
{
    public function __construct(private Config $config) {}

    public function rate(string $from, string $to): ExchangeRate
    {
        if ($from === $to) {
            return new ExchangeRate($from, $to, ExchangeRate::SCALE, new DateTimeImmutable);
        }

        $rates = $this->config->get('fanfolio.fake.fx_rates', []);
        $micros = is_array($rates) ? ($rates[$from.':'.$to] ?? null) : null;

        if (! is_int($micros)) {
            throw new RuntimeException("No fake FX rate for {$from}:{$to}.");
        }

        return new ExchangeRate($from, $to, $micros, new DateTimeImmutable);
    }
}
