<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Actions\CompleteLogin;
use App\Modules\Identity\Actions\Devices;
use App\Modules\Identity\Actions\LoginWithPassword;
use App\Modules\Identity\Actions\PendingLogin;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class LoginController
{
    use SignsIn;

    public function show(): View
    {
        return view('identity::auth.login');
    }

    public function store(Request $request, LoginWithPassword $login, CompleteLogin $complete): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        try {
            $user = $login->handle($request->string('email')->toString(), $request->string('password')->toString());
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        // A password alone never signs in an account that has a second factor.
        if ($user->mfa_enabled) {
            PendingLogin::start($request->session(), $user);

            return redirect()->route('login.mfa');
        }

        if (! $this->signIn($request, $user, 'password', $complete)) {
            return redirect()->route('login.devices');
        }

        return redirect()->intended(route('account'));
    }

    public function destroy(Request $request, AuditLog $audit, Devices $devices): RedirectResponse
    {
        $user = $request->user();
        $deviceId = $request->session()->get(Devices::SESSION_KEY);

        // Logging out frees this device's slot.
        if ($user instanceof User && is_string($deviceId) && $devices->isActiveFor($user, $deviceId)) {
            $devices->revoke($user, $deviceId);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user instanceof User) {
            $audit->record('identity.logout', $user->id, 'user', $user->id);
        }

        return redirect()->route('landing');
    }
}
