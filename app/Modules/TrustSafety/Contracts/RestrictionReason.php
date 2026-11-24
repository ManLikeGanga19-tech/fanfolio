<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Contracts;

/**
 * Why staff restricted an account (plan M8a, use case 24). The user's email names the
 * category only; the staff note stays in the audit log.
 */
enum RestrictionReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case Leak = 'leak';
    case Fraud = 'fraud';
    case Impersonation = 'impersonation';
    case MinorSafety = 'minor_safety';
    case Terms = 'terms';

    /** Shown to staff and, in the email, to the user. */
    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam or scams',
            self::Harassment => 'Harassment or hate',
            self::Leak => 'Sharing paid content outside FanFolio',
            self::Fraud => 'Payment fraud',
            self::Impersonation => 'Pretending to be someone else',
            self::MinorSafety => 'Safety of minors',
            self::Terms => 'Breaking the terms',
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
