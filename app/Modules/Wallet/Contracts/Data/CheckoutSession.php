<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

final readonly class CheckoutSession
{
    public function __construct(
        public string $gatewayRef,
        public string $redirectUrl,
    ) {}
}
