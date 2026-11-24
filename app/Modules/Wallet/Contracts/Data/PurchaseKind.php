<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

/** What a fan spends credits on (spec postings: subscribe, PPV, tip, paid message). */
enum PurchaseKind: string
{
    case Subscription = 'subscription';
    /** A pay-per-view post. */
    case Unlock = 'unlock';
    case Tip = 'tip';
    /** A paid message unlock (M6). */
    case Message = 'message';
}
