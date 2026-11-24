<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

enum KycStatus: string
{
    case NotStarted = 'not_started';
    /** With the provider. */
    case Pending = 'pending';
    /** Provider passed the ID and selfie; waiting for staff ("Manual review"). */
    case Review = 'review';
    case Approved = 'approved';
    /** Something to fix, such as a blurry ID photo ("One thing to fix"). */
    case ActionNeeded = 'action_needed';
    /** Failed, under 18 or suspected fraud. */
    case Rejected = 'rejected';
}
