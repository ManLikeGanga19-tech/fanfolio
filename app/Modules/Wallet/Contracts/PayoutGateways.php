<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts;

use App\Modules\Wallet\Contracts\Data\PayoutRail;

interface PayoutGateways
{
    public function for(PayoutRail $rail): PayoutGateway;
}
