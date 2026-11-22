<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

enum TopUpMethod: string
{
    case Card = 'card';
    case Crypto = 'crypto';
}
