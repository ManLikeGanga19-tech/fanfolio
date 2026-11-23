<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/** What staff ask a creator to fix before they can approve ("One thing to fix", board 8). */
enum FixKind: string
{
    /** The ID photo could not be read: a new ID and selfie check. */
    case RetakeId = 'retake_id';
    /** For example the name on the account does not match the ID. */
    case Payout = 'payout';
    /** For example a display name or handle that breaks the rules. */
    case Profile = 'profile';

    public function label(): string
    {
        return match ($this) {
            self::RetakeId => 'Retake ID photo',
            self::Payout => 'Change payout method',
            self::Profile => 'Edit your page',
        };
    }

    /** Shown to the creator when staff add no note of their own. */
    public function message(): string
    {
        return match ($this) {
            self::RetakeId => 'Your ID photo was too blurry to read. Everything else is fine.',
            self::Payout => 'Your payout account must be in the name on your ID.',
            self::Profile => 'Something on your page needs changing before we can approve it.',
        };
    }

    /** The onboarding route where the creator fixes it. */
    public function route(): string
    {
        return match ($this) {
            self::RetakeId => 'creator.kyc',
            self::Payout => 'creator.payout',
            self::Profile => 'creator.profile',
        };
    }
}
