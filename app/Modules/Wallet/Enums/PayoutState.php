<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Enums;

/** Where a withdrawal is (canvas StudioEarnings history and AdminPayouts). */
enum PayoutState: string
{
    /** Waiting for an admin: over $1,000 or flagged. */
    case Review = 'review';
    /** An admin asked the creator for more information; funds stay locked. */
    case Held = 'held';
    /** Approved (automatically or by an admin); about to be sent. */
    case Approved = 'approved';
    /** Handed to the payout provider, waiting for its answer. */
    case Sending = 'sending';
    case Sent = 'sent';
    /** The provider refused it; the money went back to earnings. */
    case Failed = 'failed';
    /** An admin refused it; the money went back to earnings. */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Review, self::Held => 'Under review',
            self::Approved, self::Sending => 'Sending',
            self::Sent => 'Sent',
            self::Failed => 'Failed · refunded',
            self::Rejected => 'Declined · refunded',
        };
    }

    /** Still holding the creator's money in payout_clearing. */
    public function inProgress(): bool
    {
        return in_array($this, [self::Review, self::Held, self::Approved, self::Sending], true);
    }
}
