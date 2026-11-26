<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

enum GatewayEventType: string
{
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
    /** The card holder disputed a top-up; the processor has already taken the money back. */
    case Chargeback = 'chargeback';
    /** The card network sided with us: the processor returns the money. */
    case DisputeWon = 'dispute_won';
    /** The card network sided with the card holder: the money stays with them. */
    case DisputeLost = 'dispute_lost';
    case Refund = 'refund';
}
