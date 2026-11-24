<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

/**
 * Ledger account types (spec data model, plus platform_promotions from D-04). Balances
 * read as credits minus debits.
 */
enum AccountType: string
{
    /** A fan's credits. Spent, never withdrawn. */
    case FanWallet = 'fan_wallet';
    /** A creator's share of sales, held for 7 days. */
    case CreatorPending = 'creator_pending';
    /** A creator's share after the hold: can be withdrawn. */
    case CreatorEarnings = 'creator_earnings';
    /** A creator's withdrawal while it is reviewed and sent. */
    case PayoutClearing = 'payout_clearing';
    /** FanFolio's 20% fee. */
    case PlatformRevenue = 'platform_revenue';
    /** FanFolio's spend on bonus credits (D-04). */
    case PlatformPromotions = 'platform_promotions';
    /** Money in transit with the payment and payout processors. */
    case ProcessorClearing = 'processor_clearing';

    /** Accounts of a person (fan or creator); the rest belong to the platform. */
    public function isPersonal(): bool
    {
        return in_array($this, [self::FanWallet, self::CreatorPending, self::CreatorEarnings, self::PayoutClearing], true);
    }

    /**
     * Accounts that must never go below zero. A transaction may allow it explicitly
     * only where an owner decision says so (D-20: chargeback clawbacks).
     */
    public function mustStayPositive(): bool
    {
        return $this->isPersonal();
    }
}
