<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Exceptions\WalletException;
use Illuminate\Support\Facades\DB;

use function in_array;

/** Decision D-21: the fan chooses USD or KSh in Account → Currency. Display only. */
final readonly class SetDisplayCurrency
{
    public function handle(string $userId, string $currency): void
    {
        if (! in_array($currency, config()->array('fanfolio.display_currencies'), true)) {
            throw new WalletException('Choose one of the currencies shown.', 'currency');
        }

        DB::table('wallet_preferences')->upsert(
            [['user_id' => $userId, 'display_currency' => $currency, 'updated_at' => now()]],
            ['user_id'],
            ['display_currency', 'updated_at'],
        );
    }
}
