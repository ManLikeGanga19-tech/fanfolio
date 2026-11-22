<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts\Data;

enum VerificationStatus: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
    case Review = 'review';
}
