<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts\Data;

use DateTimeImmutable;

/**
 * The provider's verdict. Personal fields (date of birth, legal name, document number)
 * must be stored only in encrypted columns and never sent to other users (SEC-10, SEC-22).
 */
final readonly class VerificationResult
{
    public function __construct(
        public string $providerRef,
        public VerificationStatus $status,
        public bool $livenessPassed,
        public bool $ageOver18,
        public DateTimeImmutable $checkedAt,
        public ?DateTimeImmutable $dateOfBirth = null,
        public ?string $legalName = null,
        public ?string $documentType = null,
        public ?string $documentLast4 = null,
        public ?DateTimeImmutable $documentExpiresAt = null,
        public bool $suspectedFraud = false,
        public ?string $failureReason = null,
    ) {}

    public function isApprovedAdult(): bool
    {
        return $this->status === VerificationStatus::Passed && $this->ageOver18 && $this->livenessPassed;
    }
}
