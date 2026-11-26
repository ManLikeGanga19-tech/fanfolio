<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * The platform settings an admin may change, with a second admin's approval (canvas
 * AdminSettings, plan M8a use cases 35 and 42, decisions D-16 and D-24). The values the
 * brief locks (20% fee, 7-day hold, $1,000 manual review, 100 credits = $1, 3 devices)
 * are not here: they stay in config and are shown read-only.
 *
 * Until an admin changes one, each setting reads its default from config/fanfolio.php.
 */
enum Setting: string
{
    /** Smallest withdrawal, in US cents. */
    case PayoutMinimum = 'money.payout_min_minor';
    /** Top-up packs: list of ['amount_minor' => int, 'bonus_bps' => int] (D-04). */
    case TopUpPacks = 'topup.packs';
    /** Fans may pay for top-ups in USDT. */
    case CryptoTopUps = 'topup.crypto';
    /** D-16: refuse a detected VPN or proxy at staff sign-in. */
    case VpnStaffSignIn = 'network_risk.staff_sign_in';
    /** D-16: refuse a detected VPN or proxy when a creator starts an ID check. */
    case VpnCreatorKyc = 'network_risk.creator_kyc';
    /** D-16: refuse a detected VPN or proxy for withdrawals and payout-method changes. */
    case VpnPayouts = 'network_risk.payouts';
    /** D-16: refuse a detected VPN or proxy for top-ups. */
    case VpnTopUps = 'network_risk.topups';

    public function label(): string
    {
        return match ($this) {
            self::PayoutMinimum => 'Minimum withdrawal',
            self::TopUpPacks => 'Top-up packs and bonuses',
            self::CryptoTopUps => 'Crypto top-ups (USDT)',
            self::VpnStaffSignIn => 'Refuse VPNs at staff sign-in',
            self::VpnCreatorKyc => 'Refuse VPNs for creator ID checks',
            self::VpnPayouts => 'Refuse VPNs for withdrawals',
            self::VpnTopUps => 'Refuse VPNs for top-ups',
        };
    }

    /** On/off switches; the rest hold numbers or lists. */
    public function isSwitch(): bool
    {
        return ! in_array($this, [self::PayoutMinimum, self::TopUpPacks], true);
    }

    /**
     * The value before any admin changed it.
     *
     * @return int|bool|list<array{amount_minor: int, bonus_bps: int}>
     */
    public function default(): int|bool|array
    {
        /** @var list<array{amount_minor: int, bonus_bps: int}> $packs config/fanfolio.php holds this shape */
        $packs = array_values(config()->array('fanfolio.topup.packs'));

        return match ($this) {
            self::PayoutMinimum => config()->integer('fanfolio.money.payout_min_minor'),
            self::TopUpPacks => $packs,
            self::CryptoTopUps => in_array('crypto', config()->array('fanfolio.topup.methods'), true),
            default => config('fanfolio.network_risk.refuse.'.substr($this->value, strlen('network_risk.'))) === true,
        };
    }
}
