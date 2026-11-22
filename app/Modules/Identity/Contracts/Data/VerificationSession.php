<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts\Data;

final readonly class VerificationSession
{
    public function __construct(
        public string $providerRef,
        public string $redirectUrl,
    ) {}
}
