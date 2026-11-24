<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Enums;

enum TopUpStatus: string
{
    /** Sent to the gateway's checkout; waiting for its signed webhook. */
    case Pending = 'pending';
    /** The gateway confirmed the payment; the credits are in the wallet. */
    case Paid = 'paid';
    case Failed = 'failed';
    /** Disputed by the card holder (M3 PR 6). */
    case ChargedBack = 'charged_back';
}
