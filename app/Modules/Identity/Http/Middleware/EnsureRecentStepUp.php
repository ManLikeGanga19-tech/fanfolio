<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Actions\RequireStepUp;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "step-up": the user must have confirmed a factor recently.
 *
 * "step-up:second-factor" (decision D-13, for payouts and payout details): the account
 * must have a passkey or authenticator app, and must have used it on the confirm page
 * in the last few minutes. Signing in, a password or a recovery code is not enough.
 */
final class EnsureRecentStepUp
{
    public const string SECOND_FACTOR = 'second-factor';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $secondFactor = $level === self::SECOND_FACTOR;
        $session = $request->session();

        if ($secondFactor ? RequireStepUp::isFreshSecondFactor($session) : RequireStepUp::isFresh($session)) {
            return $next($request);
        }

        $user = $request->user();
        $hasFactor = $user instanceof User && ($user->hasPasskey() || $user->hasConfirmedTotp());

        if ($secondFactor && ! $hasFactor) {
            $message = 'Payouts need a passkey or an authenticator app. Set one up first.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'setup' => route('account.security')], 403);
            }

            if ($request->isMethod('GET')) {
                $session->put('url.intended', $request->fullUrl());
            }

            return redirect()->route('account.security')->with('status', $message);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $secondFactor ? 'Confirm with your passkey or authenticator app first.' : 'Confirm it is you first.',
                'confirm' => route('account.confirm'),
            ], 403);
        }

        if ($secondFactor) {
            $session->put(RequireStepUp::NEEDS_SECOND_FACTOR, true);
        }

        if ($request->isMethod('GET')) {
            $session->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('account.confirm');
    }
}
