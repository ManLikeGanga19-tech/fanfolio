<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts\Data;

enum DocumentSide: string
{
    case Front = 'front';
    case Back = 'back';
    case SelfieFrame = 'selfie_frame';
}
