<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts\Data;

enum ClassifierVerdict: string
{
    case Allow = 'allow';
    case Review = 'review';
    case Block = 'block';
}
