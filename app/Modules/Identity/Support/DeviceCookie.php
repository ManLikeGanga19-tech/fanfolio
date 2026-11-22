<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The device token: 32 random bytes in an encrypted, HttpOnly, SameSite=Lax cookie.
 * Only its keyed hash is stored (user_devices.device_fingerprint_hash).
 */
final class DeviceCookie
{
    public const string NAME = 'ff_device';

    private const int MINUTES = 60 * 24 * 400;

    public static function read(Request $request): ?string
    {
        $token = $request->cookie(self::NAME);

        return is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1 ? $token : null;
    }

    /**
     * Returns this browser's token, issuing one if it has none.
     */
    public static function ensure(Request $request): string
    {
        $token = self::read($request);

        if ($token === null) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $request->cookies->set(self::NAME, $token);
        }

        Cookie::queue(Cookie::make(
            self::NAME,
            $token,
            self::MINUTES,
            '/',
            null,
            config()->boolean('session.secure'),
            true,
            false,
            'lax',
        ));

        return $token;
    }
}
