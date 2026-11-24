<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * Decision D-16: is this address a VPN, proxy or other anonymising network? Fans may
 * browse behind one; it is refused for staff sign-in, creator ID checks, payouts and
 * payout-method changes, and top-ups, each switchable in `fanfolio.network_risk.refuse`.
 * The IP-reputation provider is not chosen (open item); a Fake driver stands in.
 */
interface NetworkRiskCheck
{
    public function isAnonymised(string $ip): bool;

    /** Whether the rule for this area is switched on (D-16; changed with two-admin approval in M3 PR 7). */
    public function refuses(string $area): bool;
}
