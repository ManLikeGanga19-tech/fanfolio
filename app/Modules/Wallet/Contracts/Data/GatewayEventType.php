<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

enum GatewayEventType: string
{
    case PaymentSucceeded = 'payment_succeeded';
    case PaymentFailed = 'payment_failed';
    case Chargeback = 'chargeback';
    case Refund = 'refund';
}
