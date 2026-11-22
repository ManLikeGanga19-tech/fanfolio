<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

enum PayoutStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
