<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

enum PayoutRail: string
{
    case Bank = 'bank';
    case Usdt = 'usdt';
    case Paxum = 'paxum';
}
