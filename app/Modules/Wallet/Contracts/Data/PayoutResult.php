<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

final readonly class PayoutResult
{
    public function __construct(
        public PayoutStatus $status,
        public string $gatewayRef,
        public ?string $failureReason = null,
    ) {}
}
