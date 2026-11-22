<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use App\Modules\Identity\Contracts\Data\DocumentImage;
use App\Modules\Identity\Contracts\Data\DocumentSide;
use App\Modules\Identity\Contracts\Data\VerificationRequest;
use App\Modules\Identity\Contracts\Data\VerificationResult;
use App\Modules\Identity\Contracts\Data\VerificationSession;

/**
 * ID document, liveness and age checks through the KYC provider (provider not chosen).
 *
 * We keep only the provider reference, result and date, never ID images or selfies (rule 10).
 */
interface IdentityVerifier
{
    public function startSession(VerificationRequest $request): VerificationSession;

    public function result(string $providerRef): VerificationResult;

    /**
     * Fetch a document image from the provider for an admin review (decision D-01).
     * The caller streams it to the reviewer and must never cache or persist it.
     */
    public function fetchDocumentImage(string $providerRef, DocumentSide $side): DocumentImage;
}
