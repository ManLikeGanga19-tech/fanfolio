<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\CompleteLogin;
use App\Modules\Identity\Actions\Devices;
use App\Modules\Identity\Actions\PendingLogin;
use App\Modules\Identity\Actions\RequireStepUp;
use App\Modules\Identity\Exceptions\DeviceLimitReached;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DeviceCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The one place a browser session is started, once every required factor passed.
 */
trait SignsIn
{
    /**
     * Registers this device and starts the session. Returns false when the account is
     * at its device limit: the login is then held so the user can sign out a device.
     */
    private function signIn(Request $request, User $user, string $method, CompleteLogin $complete): bool
    {
        try {
            $device = app(Devices::class)->register($user, DeviceCookie::ensure($request), $request->userAgent(), $request->ip());
        } catch (DeviceLimitReached) {
            PendingLogin::start($request->session(), $user, PendingLogin::DEVICES, $method);

            return false;
        }

        PendingLogin::clear($request->session());

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(Devices::SESSION_KEY, $device->id);

        // Signing in counts as a fresh confirmation for step-up.
        RequireStepUp::confirm($request->session());

        $complete->handle($user, $method);

        return true;
    }
}
