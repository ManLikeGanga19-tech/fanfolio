<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\Setting;
use App\Modules\Identity\Exceptions\IdentityException;

/**
 * What a valid value looks like for each setting, and how it reads ("$20", "$5 · $10 ·
 * $25 (+4%) · $50 (+10%)", "On"). The limits are guard rails against typing mistakes,
 * not product values: a withdrawal minimum between $1 and the $1,000 manual-review line
 * (rule 3), one to six packs of whole dollars up to $1,000, in rising order, each with a
 * whole-percent bonus of at most 50%.
 */
final class SettingValues
{
    public const int MAX_PACKS = 6;

    public const int MAX_PACK_MINOR = 100_000;

    public const int MAX_BONUS_BPS = 5_000;

    /**
     * @return int|bool|list<array{amount_minor: int, bonus_bps: int}>
     */
    public static function normalise(Setting $setting, mixed $value): int|bool|array
    {
        return match ($setting) {
            Setting::PayoutMinimum => self::minimum($value),
            Setting::TopUpPacks => self::packs($value),
            default => self::toggle($setting, $value),
        };
    }

    /** Whether two values are the same setting value (jsonb keeps keys in its own order). */
    public static function same(mixed $a, mixed $b): bool
    {
        return self::canonical($a) === self::canonical($b);
    }

    public static function describe(Setting $setting, mixed $value): string
    {
        if ($setting === Setting::PayoutMinimum) {
            return self::dollars(is_int($value) ? $value : 0);
        }

        if ($setting === Setting::TopUpPacks) {
            /** @var list<array{amount_minor: int, bonus_bps: int}> $packs */
            $packs = is_array($value) ? $value : [];

            return implode(' · ', array_map(static fn (array $pack): string => self::dollars($pack['amount_minor']).($pack['bonus_bps'] > 0 ? ' (+'.intdiv($pack['bonus_bps'], 100).'%)' : ''), $packs));
        }

        return $value === true ? 'On' : 'Off';
    }

    /** "$20", or "$12.50" when there are cents. */
    public static function dollars(int $cents): string
    {
        return '$'.number_format(intdiv($cents, 100)).($cents % 100 === 0 ? '' : '.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);

        return array_map(self::canonical(...), $value);
    }

    private static function minimum(mixed $value): int
    {
        $ceiling = config()->integer('fanfolio.money.payout_manual_review_above_minor');

        if (! is_int($value) || $value < 100 || $value > $ceiling) {
            throw new IdentityException('Enter a minimum withdrawal between $1 and '.self::dollars($ceiling).'.', Setting::PayoutMinimum->value);
        }

        return $value;
    }

    /**
     * @return list<array{amount_minor: int, bonus_bps: int}>
     */
    private static function packs(mixed $value): array
    {
        $invalid = new IdentityException('Enter one to six packs of whole dollars up to $1,000, smallest first, each with a whole-percent bonus of at most 50%.', Setting::TopUpPacks->value);

        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > self::MAX_PACKS) {
            throw $invalid;
        }

        $packs = [];
        $previous = 0;
        foreach ($value as $pack) {
            $amount = is_array($pack) ? ($pack['amount_minor'] ?? null) : null;
            $bonus = is_array($pack) ? ($pack['bonus_bps'] ?? null) : null;

            if (! is_int($amount) || ! is_int($bonus) || $amount <= $previous || $amount > self::MAX_PACK_MINOR || $amount % 100 !== 0 || $bonus < 0 || $bonus > self::MAX_BONUS_BPS || $bonus % 100 !== 0) {
                throw $invalid;
            }

            $packs[] = ['amount_minor' => $amount, 'bonus_bps' => $bonus];
            $previous = $amount;
        }

        return $packs;
    }

    private static function toggle(Setting $setting, mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new IdentityException('Choose on or off.', $setting->value);
        }

        return $value;
    }
}
