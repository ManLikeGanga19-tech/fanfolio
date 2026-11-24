<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Actions\Passkeys;
use App\Modules\Identity\Actions\RecoveryCodes;
use App\Modules\Identity\Actions\RequireStepUp;
use App\Modules\Identity\Actions\Totp;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;

/**
 * "Confirm it's you" before a sensitive change. Users with MFA must use a factor;
 * the password is accepted only from accounts that have no second factor.
 */
final class StepUpController
{
    public function show(Request $request): View
    {
        $user = $this->user($request);

        // D-13: a payout asked for a passkey or authenticator code only.
        $secondFactorOnly = $request->session()->get(RequireStepUp::NEEDS_SECOND_FACTOR) === true;

        return view('identity::account.confirm', [
            'hasPasskey' => $user->hasPasskey(),
            'hasTotp' => $user->hasConfirmedTotp(),
            'usesPassword' => ! $user->mfa_enabled && ! $secondFactorOnly,
            'secondFactorOnly' => $secondFactorOnly,
        ]);
    }

    public function store(Request $request, Totp $totp, RecoveryCodes $recoveryCodes, Hasher $hasher, AuditLog $audit): RedirectResponse
    {
        $user = $this->user($request);

        $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'max:200'],
        ]);

        $code = $request->string('code')->toString();
        $recovery = $request->string('recovery_code')->toString();
        $password = $request->string('password')->toString();

        $passed = match (true) {
            $code !== '' => $totp->verify($user, $code),
            $recovery !== '' => $recoveryCodes->use($user, $recovery),
            $password !== '' && ! $user->mfa_enabled && $user->password !== null => $hasher->check($password, $user->password),
            default => false,
        };

        if (! $passed) {
            $audit->record('identity.step_up_failed', $user->id, 'user', $user->id);

            throw ValidationException::withMessages(['code' => 'That did not match. Try again.']);
        }

        // Only an authenticator code counts as the second factor payouts need (D-13).
        return $this->confirmed($request, $user, $audit, secondFactor: $code !== '');
    }

    public function passkeyOptions(AssertionRequest $request): Responsable
    {
        return $request->secureLogin()->toVerify($this->user($request));
    }

    public function passkey(Request $request, Passkeys $passkeys, AuditLog $audit): JsonResponse
    {
        $user = $this->user($request);

        try {
            $passkeys->assert($request, $user);
        } catch (IdentityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['redirect' => $this->confirmed($request, $user, $audit, secondFactor: true)->getTargetUrl()]);
    }

    private function confirmed(Request $request, User $user, AuditLog $audit, bool $secondFactor): RedirectResponse
    {
        if ($secondFactor) {
            RequireStepUp::confirmSecondFactor($request->session());
        } else {
            RequireStepUp::confirm($request->session());
        }

        $audit->record('identity.step_up', $user->id, 'user', $user->id, ['second_factor' => $secondFactor]);

        return redirect()->intended(route('account.security'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
