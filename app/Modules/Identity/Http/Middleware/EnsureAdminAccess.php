<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-15 and rule 13: the admin panel is for staff with MFA, from allowed addresses,
 * and behind SSO once a provider is chosen. Every refusal and every new admin session
 * is audited.
 */
final readonly class EnsureAdminAccess
{
    private const string OPENED_KEY = 'identity.admin_opened';

    public function __construct(private AuditLog $audit) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->guest(route('login'));
        }

        if (! $user->role->isStaff()) {
            return $this->deny($user, 'not_staff');
        }

        if (! $this->addressAllowed($request)) {
            return $this->deny($user, 'ip_not_allowed');
        }

        if (config()->boolean('fanfolio.admin.sso_required')) {
            // No SSO provider yet (open decision): with the switch on, the panel stays closed.
            return $this->deny($user, 'sso_required');
        }

        // Rule 13: no MFA, no admin. Staff are sent to set it up first.
        if (! $user->mfa_enabled) {
            $this->audit->record('admin.access_denied', $user->id, 'user', $user->id, ['reason' => 'mfa_missing']);

            return redirect()->route('account.security')
                ->with('status', 'Your account needs a passkey or an authenticator app. Set one up to continue.');
        }

        if (! $request->session()->has(self::OPENED_KEY)) {
            $request->session()->put(self::OPENED_KEY, true);
            $this->audit->record('admin.panel_opened', $user->id, 'user', $user->id);
        }

        return $next($request);
    }

    private function addressAllowed(Request $request): bool
    {
        /** @var list<string> $allowed */
        $allowed = config()->array('fanfolio.admin.ip_allowlist');

        if ($allowed === []) {
            return app()->environment('local', 'testing');
        }

        $ip = $request->ip();

        return $ip !== null && IpUtils::checkIp($ip, $allowed);
    }

    private function deny(User $user, string $reason): Response
    {
        $this->audit->record('admin.access_denied', $user->id, 'user', $user->id, ['reason' => $reason]);

        abort(403, $reason === 'sso_required'
            ? 'Single sign-on is required for the admin panel.'
            : 'You do not have access to this page.');
    }
}
