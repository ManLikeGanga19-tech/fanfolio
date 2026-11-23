<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\Devices;
use App\Modules\Identity\Actions\Passkeys;
use App\Modules\Identity\Actions\RecoveryCodes;
use App\Modules\Identity\Actions\Totp;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\TotpSecret;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserDevice;
use App\Modules\Identity\Support\DeviceLabel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

/**
 * Screen "Devices & security", sign-in section: passkeys, authenticator app and
 * recovery codes. Every change here needs a recent step-up.
 */
final class SecurityController
{
    public function show(Request $request, RecoveryCodes $recoveryCodes, Devices $devices): View
    {
        $user = $this->user($request);

        return view('identity::account.security', [
            'user' => $user,
            'devices' => $devices->active($user),
            'currentDeviceId' => $request->session()->get(Devices::SESSION_KEY),
            'deviceLimit' => Devices::limit(),
            'passkeys' => $user->webAuthnCredentials()->whereNull('disabled_at')->orderBy('created_at')->get(),
            'hasTotp' => $user->hasConfirmedTotp(),
            'recoveryCodesLeft' => $recoveryCodes->remaining($user),
        ]);
    }

    /**
     * RevokeDevice: signs a device out. Signing out this device logs out here too.
     */
    public function destroyDevice(Request $request, Devices $devices, UserDevice $device): RedirectResponse
    {
        $user = $this->user($request);
        Gate::authorize('revoke', $device);

        try {
            $devices->revoke($user, $device->id);
        } catch (IdentityException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        if ($device->id === $request->session()->get(Devices::SESSION_KEY)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return redirect()->route('account.security')->with('status', "Signed out of {$device->label}.");
    }

    public function signOutOtherDevices(Request $request, Devices $devices): RedirectResponse
    {
        $current = $request->session()->get(Devices::SESSION_KEY);
        abort_unless(is_string($current), 409);

        $count = $devices->revokeOthers($this->user($request), $current);

        return redirect()->route('account.security')->with('status', $count === 0 ? 'No other devices were signed in.' : 'Signed out of all other devices.');
    }

    public function passkeyOptions(AttestationRequest $request): Responsable
    {
        // Resident (discoverable) keys with user verification: they work without typing an email.
        return $request->secureRegistration()->userless()->toCreate();
    }

    public function storePasskey(AttestedRequest $request, Passkeys $passkeys): JsonResponse
    {
        $passkeys->register($this->user($request), $request, DeviceLabel::fromUserAgent($request->userAgent()));

        return response()->json(['redirect' => route('account.security')]);
    }

    public function destroyPasskey(Request $request, Passkeys $passkeys, string $credential): RedirectResponse
    {
        try {
            $passkeys->remove($this->user($request), $credential);
        } catch (IdentityException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return redirect()->route('account.security')->with('status', 'Passkey removed.');
    }

    public function startTotp(Request $request, Totp $totp): RedirectResponse
    {
        try {
            $totp->enable($this->user($request));
        } catch (IdentityException $e) {
            return back()->withErrors(['totp' => $e->getMessage()]);
        }

        return redirect()->route('account.totp.show');
    }

    public function showTotp(Request $request): View|RedirectResponse
    {
        $user = $this->user($request);
        $pending = TotpSecret::query()->where('user_id', $user->id)->whereNull('confirmed_at')->first();

        if ($pending === null) {
            return redirect()->route('account.security');
        }

        return view('identity::account.totp', [
            'secret' => trim(chunk_split($pending->secret, 4, ' ')),
            'uri' => Totp::uri($user, $pending->secret),
        ]);
    }

    public function confirmTotp(Request $request, Totp $totp): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:10']]);

        try {
            $codes = $totp->confirm($this->user($request), $request->string('code')->toString());
        } catch (IdentityException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        if ($codes === []) {
            return redirect()->route('account.security')->with('status', 'Authenticator app is on.');
        }

        return redirect()->route('account.security.recovery-codes')->with('recovery_codes', $codes);
    }

    public function destroyTotp(Request $request, Totp $totp): RedirectResponse
    {
        try {
            $totp->remove($this->user($request));
        } catch (IdentityException $e) {
            return back()->withErrors(['totp' => $e->getMessage()]);
        }

        return redirect()->route('account.security')->with('status', 'Authenticator app removed.');
    }

    public function regenerateRecoveryCodes(Request $request, RecoveryCodes $recoveryCodes): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->mfa_enabled, 403);

        return redirect()->route('account.security.recovery-codes')->with('recovery_codes', $recoveryCodes->issue($user));
    }

    public function showRecoveryCodes(Request $request): View|RedirectResponse
    {
        $codes = $request->session()->get('recovery_codes');

        // Shown once, straight after they are made.
        if (! is_array($codes) || $codes === []) {
            return redirect()->route('account.security');
        }

        // Straight after "Continue with a passkey", sign-up carries on to the phone step.
        $next = $request->session()->pull(SignUpController::CONTINUE_SIGNUP) === true
            ? route('signup.phone')
            : route('account.security');

        return view('identity::account.recovery-codes', ['codes' => $codes, 'next' => $next]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
