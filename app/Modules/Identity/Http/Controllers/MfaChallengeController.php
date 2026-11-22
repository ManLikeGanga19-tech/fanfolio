<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Actions\CompleteLogin;
use App\Modules\Identity\Actions\PendingLogin;
use App\Modules\Identity\Actions\RecoveryCodes;
use App\Modules\Identity\Actions\Totp;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Second step of a password login for accounts with MFA: an authenticator code or a
 * recovery code. Accounts with a passkey can also use "Log in with passkey" instead.
 */
final class MfaChallengeController
{
    use SignsIn;

    public function show(Request $request): View|RedirectResponse
    {
        $user = PendingLogin::user($request->session());

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        return view('identity::auth.mfa', [
            'hasTotp' => $user->hasConfirmedTotp(),
            'hasPasskey' => $user->hasPasskey(),
        ]);
    }

    public function store(Request $request, Totp $totp, RecoveryCodes $recoveryCodes, CompleteLogin $complete, AuditLog $audit): RedirectResponse
    {
        $user = PendingLogin::user($request->session());

        if (! $user instanceof User) {
            return redirect()->route('login')->withErrors(['email' => 'That took too long. Log in again.']);
        }

        $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:20'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:20'],
        ]);

        $code = $request->string('code')->toString();
        $recovery = $request->string('recovery_code')->toString();

        $passed = $code !== ''
            ? $totp->verify($user, $code)
            : $recoveryCodes->use($user, $recovery);

        if (! $passed) {
            $audit->record('identity.login_failed', null, 'user', $user->id, ['reason' => $code !== '' ? 'totp' : 'recovery_code']);

            throw ValidationException::withMessages([
                $code !== '' ? 'code' : 'recovery_code' => 'That code is not right. Try again.',
            ]);
        }

        $this->signIn($request, $user, $code !== '' ? 'password+totp' : 'password+recovery_code', $complete);

        return redirect()->intended(route('account'));
    }
}
