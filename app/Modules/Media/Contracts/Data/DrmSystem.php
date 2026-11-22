<?php

declare(strict_types=1);

namespace App\Modules\Media\Contracts\Data;

enum DrmSystem: string
{
    case Widevine = 'widevine';
    case FairPlay = 'fairplay';
    case PlayReady = 'playready';
    /** Development only. Never accepted outside local and testing. */
    case ClearKey = 'clearkey';
}
