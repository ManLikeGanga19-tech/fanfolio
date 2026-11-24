<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Enums;

/**
 * Plan M8a use case 27: what a moderator or admin decides on an account case. Content
 * decisions on the canvas (approve, remove, remove + strike, escalate & report) arrive
 * with posts in M4.
 */
enum CaseDecision: string
{
    case NoAction = 'no_action';
    case Warn = 'warn';
    case Strike = 'strike';
    case Suspend = 'suspend';
    case Escalate = 'escalate';

    public function label(): string
    {
        return match ($this) {
            self::NoAction => 'No action',
            self::Warn => 'Warn',
            self::Strike => 'Strike',
            self::Suspend => 'Suspend',
            self::Escalate => 'Escalate to admin',
        };
    }

    /** Decisions that act on the account need a reason category the user is told. */
    public function needsReason(): bool
    {
        return in_array($this, [self::Warn, self::Strike, self::Suspend], true);
    }
}
