<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Support;

use App\Modules\Wallet\Actions\WalletDisplayService;
use App\Support\Money\Money;

/**
 * A top-up pack from `fanfolio.topup.packs` (D-04): what the fan pays, the credits it
 * buys (100 credits = $1) and the bonus credits on top. Integers only.
 */
final readonly class TopUpPack
{
    private function __construct(
        public int $index,
        public Money $price,
        public int $credits,
        public int $bonusCredits,
        public int $bonusBps,
    ) {}

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        $packs = [];
        foreach (config()->array('fanfolio.topup.packs') as $index => $pack) {
            /** @var array{amount_minor: int, bonus_bps: int} $pack */
            $credits = intdiv($pack['amount_minor'] * config()->integer('fanfolio.money.credits_per_usd'), 100);
            $packs[] = new self(
                (int) $index,
                Money::of($pack['amount_minor'], config()->string('fanfolio.money.currency')),
                $credits,
                intdiv($credits * $pack['bonus_bps'], 10_000),
                $pack['bonus_bps'],
            );
        }

        return $packs;
    }

    public static function find(int $index): ?self
    {
        return self::all()[$index] ?? null;
    }

    public function totalCredits(): int
    {
        return $this->credits + $this->bonusCredits;
    }

    /** "$5" for whole dollars (as on the canvas), "$5.50" otherwise. */
    public function priceLabel(): string
    {
        $cents = $this->price->amountMinor;

        return $cents % 100 === 0 ? '$'.number_format(intdiv($cents, 100)) : WalletDisplayService::dollars($cents);
    }

    /** "+4%" on the canvas; whole percent, rounded down. */
    public function bonusLabel(): ?string
    {
        return $this->bonusBps > 0 ? '+'.intdiv($this->bonusBps, 100).'%' : null;
    }
}
