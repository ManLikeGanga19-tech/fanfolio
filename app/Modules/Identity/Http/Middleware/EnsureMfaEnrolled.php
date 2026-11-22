<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-04: creators, moderators and admins must have MFA. Until they do, every page
 * except security set-up, step-up and log out sends them to set it up.
 */
final class EnsureMfaEnrolled
{
    private const array ALLOWED = [
        'account.security',
        'account.security.*',
        'account.passkeys.*',
        'account.totp.*',
        'account.confirm',
        'account.confirm.*',
        'logout',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->role->requiresMfa() && ! $user->mfa_enabled && ! $request->routeIs(...self::ALLOWED)) {
            if ($request->expectsJson()) {
                abort(403, 'Set up a passkey or authenticator app first.');
            }

            return redirect()->route('account.security')
                ->with('status', 'Your account needs a passkey or an authenticator app. Set one up to continue.');
        }

        return $next($request);
    }
}
