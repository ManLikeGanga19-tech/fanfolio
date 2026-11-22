<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts;

use App\Modules\Wallet\Contracts\Data\PayoutInstruction;
use App\Modules\Wallet\Contracts\Data\PayoutRail;
use App\Modules\Wallet\Contracts\Data\PayoutResult;

/**
 * Sends creator payouts on one rail: bank, USDT or Paxum (providers not contracted).
 * send() must be idempotent on the instruction's payoutId.
 */
interface PayoutGateway
{
    public function rail(): PayoutRail;

    public function send(PayoutInstruction $instruction): PayoutResult;

    public function status(string $gatewayRef): PayoutResult;
}
