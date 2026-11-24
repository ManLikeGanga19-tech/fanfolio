<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Middleware;

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Identity\Contracts\Accounts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /studio panel: only creators who may publish (approved, ID valid, not suspended)
 * and who have MFA (SEC-04). Everyone else goes to the creator status screen, which
 * says why.
 */
final readonly class EnsureStudioAccess
{
    public function __construct(
        private CreatorEligibility $eligibility,
        private Accounts $accounts,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->user()?->getAuthIdentifier();

        if (! is_string($id)) {
            return redirect()->guest(route('login'));
        }

        if (! $this->accounts->hasMfa($id)) {
            return redirect()->route('account.security')
                ->with('status', 'Creators need a passkey or an authenticator app. Set one up to continue.');
        }

        if (! $this->eligibility->canPublish($id)) {
            return redirect()->route('creator.status');
        }

        return $next($request);
    }
}
