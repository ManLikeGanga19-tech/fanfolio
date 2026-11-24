<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

enum CreatorStatus: string
{
    /** Filling in the onboarding steps. */
    case Onboarding = 'onboarding';
    /** Sent for review ("We're reviewing your account"). */
    case Submitted = 'submitted';
    /** Approved by staff: can publish and earn while KYC stays valid. */
    case Active = 'active';
    /** Stopped by staff: no publishing, no earning. */
    case Suspended = 'suspended';
}
