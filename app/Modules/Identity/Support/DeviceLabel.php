<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

/**
 * A readable label such as "Chrome on Android" from a user agent. Used for passkeys
 * and the active devices list. Never used for security decisions.
 */
final class DeviceLabel
{
    private const array BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    private const array SYSTEMS = [
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Android' => 'Android',
        'Macintosh' => 'Mac',
        'Windows' => 'Windows',
        'CrOS' => 'Chromebook',
        'Linux' => 'Linux',
    ];

    public static function fromUserAgent(?string $userAgent): string
    {
        $userAgent ??= '';

        $browser = self::first(self::BROWSERS, $userAgent) ?? 'Browser';
        $system = self::first(self::SYSTEMS, $userAgent);

        return $system === null ? $browser : "{$browser} on {$system}";
    }

    /**
     * @param  array<string, string>  $needles
     */
    private static function first(array $needles, string $haystack): ?string
    {
        foreach ($needles as $needle => $label) {
            if (str_contains($haystack, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
