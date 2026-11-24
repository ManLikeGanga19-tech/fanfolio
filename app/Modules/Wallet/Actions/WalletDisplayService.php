<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\FxRateProvider;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\WalletDisplay;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Throwable;

use function in_array;
use function is_string;
use function preg_match;

/**
 * Decision D-21. Credits are US cents; the approximate amount is for display only and
 * is never stored or charged. Integers throughout (no floats for money).
 */
final readonly class WalletDisplayService implements WalletDisplay
{
    public function __construct(private Ledger $ledger) {}

    public function credits(string $userId): int
    {
        return $this->ledger->balance(AccountRef::of(AccountType::FanWallet, $userId))->amountMinor;
    }

    public function currency(string $userId, ?string $acceptLanguage = null): string
    {
        $chosen = DB::table('wallet_preferences')->where('user_id', $userId)->value('display_currency');

        if (is_string($chosen) && in_array($chosen, config()->array('fanfolio.display_currencies'), true)) {
            return $chosen;
        }

        // A Kenyan browser (en-KE, sw-KE, sw) sees shillings; everyone else dollars.
        return preg_match('/(^|[\s,])sw\b|-ke\b/i', (string) $acceptLanguage) === 1 ? 'KES' : 'USD';
    }

    public function approximate(int $credits, string $currency): ?string
    {
        $usd = Money::of($credits, Money::CREDIT_CURRENCY);

        if ($currency === Money::CREDIT_CURRENCY) {
            return '≈ '.self::dollars($usd->amountMinor);
        }

        try {
            // Resolved here, not injected: in production the fake rate driver refuses to load,
            // and the amount is left out until the live rate feed is chosen (D-21).
            $local = app(FxRateProvider::class)->rate(Money::CREDIT_CURRENCY, $currency)->convert($usd);
        } catch (Throwable) {
            return null;
        }

        return '≈ '.($currency === 'KES' ? 'KSh' : $currency).' '.number_format(intdiv($local->amountMinor, 100));
    }

    /** "$12.50" from cents. */
    public static function dollars(int $cents): string
    {
        return ($cents < 0 ? '−' : '').'$'.number_format(intdiv(abs($cents), 100)).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }
}
