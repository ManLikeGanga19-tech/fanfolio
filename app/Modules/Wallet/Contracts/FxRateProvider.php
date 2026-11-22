<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts;

use App\Modules\Wallet\Contracts\Data\ExchangeRate;

/**
 * Exchange rates for display estimates only, e.g. "250 credits · ≈ KES 330".
 * The ledger always stays in USD minor units.
 */
interface FxRateProvider
{
    public function rate(string $from, string $to): ExchangeRate;
}
