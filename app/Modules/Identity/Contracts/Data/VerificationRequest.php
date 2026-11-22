<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts\Data;

final readonly class VerificationRequest
{
    public function __construct(
        public string $subjectId,
        public VerificationPurpose $purpose,
        public string $returnUrl,
    ) {}
}
