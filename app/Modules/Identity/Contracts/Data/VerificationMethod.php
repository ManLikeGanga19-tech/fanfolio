<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts\Data;

/**
 * How the provider checks the person: an ID document with a selfie match, or a
 * document-free selfie age estimate (screen "Age verification": two options).
 */
enum VerificationMethod: string
{
    case Document = 'document';
    case SelfieAgeEstimate = 'selfie_age';
}
