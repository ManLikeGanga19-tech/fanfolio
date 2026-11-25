<?php

declare(strict_types=1);

namespace App\Modules\Creators\Contracts\Data;

/** An undecided creator application, as other modules may show it. */
enum ApplicationStage: string
{
    /** Filling in the onboarding steps. */
    case Started = 'started';
    /** Sent for review and not decided yet. */
    case InReview = 'in_review';
}
