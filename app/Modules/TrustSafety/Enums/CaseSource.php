<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Enums;

/**
 * Where a moderation case came from: the queue tabs on canvas AdminModeration. User
 * reports and staff flags arrive in M8a; the others with their milestones (hash match
 * and AI flags with media scanning in M4, consent withdrawn with collaborations in M7,
 * leaks with leak takedowns in M4).
 */
enum CaseSource: string
{
    case HashMatch = 'hash_match';
    case AiFlag = 'ai_flag';
    case UserReport = 'user_report';
    case StaffFlag = 'staff_flag';
    case ConsentWithdrawn = 'consent_withdrawn';
    case Leak = 'leak';

    public function label(): string
    {
        return match ($this) {
            self::HashMatch => 'Hash match',
            self::AiFlag => 'AI flagged',
            self::UserReport => 'User report',
            self::StaffFlag => 'Flagged by staff',
            self::ConsentWithdrawn => 'Consent withdrawn',
            self::Leak => 'Leak',
        };
    }

    /** The tab's label (plural where the canvas uses it). */
    public function tab(): string
    {
        return match ($this) {
            self::UserReport => 'User reports',
            self::Leak => 'Leaks',
            default => $this->label(),
        };
    }
}
