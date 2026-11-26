<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * Decision D-16: is this address a VPN, proxy or other anonymising network? Fans may
 * browse behind one; it is refused for staff sign-in, creator ID checks, payouts and
 * payout-method changes, and top-ups. Whether each rule is on is a platform setting
 * (PlatformSettings, Setting::Vpn*), changed with two-admin approval.
 * The IP-reputation provider is not chosen (open item); a Fake driver stands in.
 */
interface NetworkRiskCheck
{
    public function isAnonymised(string $ip): bool;
}
