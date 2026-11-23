<?php

declare(strict_types=1);

namespace App\Modules\Creators\Support;

/**
 * Creator page handles ("[domain]/amani.w"): 3–30 characters, lowercase letters,
 * digits, dots and underscores, starting and ending with a letter or digit, no two
 * dots together, and not a reserved word. Uniqueness ignores case (citext).
 */
final class HandleRules
{
    public const string PATTERN = '/^(?=.{3,30}$)[a-z0-9](?:[a-z0-9_]|\.(?!\.))*[a-z0-9]$/';

    public static function normalise(string $handle): string
    {
        return mb_strtolower(ltrim(trim($handle), '@'));
    }

    /** Why the handle cannot be used, or null when its shape is fine. */
    public static function problem(string $handle): ?string
    {
        if (preg_match(self::PATTERN, $handle) !== 1) {
            return 'Use 3 to 30 lowercase letters, numbers, dots or underscores. Start and end with a letter or number.';
        }

        /** @var list<string> $reserved */
        $reserved = config()->array('fanfolio.creators.reserved_handles');

        if (in_array($handle, $reserved, true)) {
            return 'This handle is reserved.';
        }

        return null;
    }
}
