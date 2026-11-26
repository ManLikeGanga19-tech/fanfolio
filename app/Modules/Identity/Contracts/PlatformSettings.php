<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * Reads the platform settings admins can change with two-admin approval (see Setting).
 * Values are cached; a change approved on the Fees & settings board applies at once.
 */
interface PlatformSettings
{
    /** A number setting (Setting::PayoutMinimum). */
    public function integer(Setting $setting): int;

    /** An on/off setting (crypto top-ups, the D-16 VPN switches). */
    public function enabled(Setting $setting): bool;

    /**
     * The top-up packs (D-04), in the order fans see them.
     *
     * @return list<array{amount_minor: int, bonus_bps: int}>
     */
    public function topUpPacks(): array;
}
