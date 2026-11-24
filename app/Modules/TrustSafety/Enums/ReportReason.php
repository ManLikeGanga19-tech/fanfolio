<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Enums;

use App\Modules\TrustSafety\Contracts\RestrictionReason;

/** The choices on the "Report" sheet (canvas FanMore · Report content), most serious first. */
enum ReportReason: string
{
    case Minor = 'minor';
    case NoConsent = 'no_consent';
    case Leak = 'leak';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Someone may be under 18',
            self::NoConsent => 'Shared without consent',
            self::Leak => 'Leaked or stolen content',
            self::Spam => 'Spam or scam',
            self::Other => 'Something else',
        };
    }

    /** Lower is more serious; a case keeps its most serious reason. */
    public function rank(): int
    {
        return match ($this) {
            self::Minor => 0,
            self::NoConsent => 1,
            self::Leak => 2,
            self::Spam => 3,
            self::Other => 4,
        };
    }

    /** Plan M8a use case 29: anything that may involve a minor is urgent, with a 1-hour target. */
    public function isUrgent(): bool
    {
        return $this === self::Minor;
    }

    /** The category a decision starts from; staff can choose another. */
    public function restriction(): RestrictionReason
    {
        return match ($this) {
            self::Minor => RestrictionReason::MinorSafety,
            self::NoConsent => RestrictionReason::Terms,
            self::Leak => RestrictionReason::Leak,
            self::Spam => RestrictionReason::Spam,
            self::Other => RestrictionReason::Terms,
        };
    }

    /** A staff flag starts from the Users & bans reason category. */
    public static function fromRestriction(RestrictionReason $reason): self
    {
        return match ($reason) {
            RestrictionReason::MinorSafety => self::Minor,
            RestrictionReason::Leak => self::Leak,
            RestrictionReason::Spam => self::Spam,
            default => self::Other,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $reason) {
            $options[$reason->value] = $reason->label();
        }

        return $options;
    }
}
