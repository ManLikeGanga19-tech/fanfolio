<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts\Data;

enum VerificationPurpose: string
{
    case CreatorKyc = 'creator';
    case CollaboratorKyc = 'collaborator';
    case FanAgeCheck = 'fan_age';
}
