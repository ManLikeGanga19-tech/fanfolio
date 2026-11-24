<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts;

/**
 * What other modules show about a fan's wallet (the Account hub's wallet card and
 * Currency row). Decision D-21: credits first, with an approximate amount in the fan's
 * display currency (USD or KSh), for display only.
 */
interface WalletDisplay
{
    /** Credits the fan can spend. */
    public function credits(string $userId): int;

    /** The fan's chosen display currency, or a guess from the browser's language (KES for Kenya, else USD). */
    public function currency(string $userId, ?string $acceptLanguage = null): string;

    /** "≈ KSh 1,640" or "≈ $12.50"; null when no exchange rate is available. */
    public function approximate(int $credits, string $currency): ?string;
}
