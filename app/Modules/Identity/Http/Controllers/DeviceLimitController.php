<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\CompleteLogin;
use App\Modules\Identity\Actions\Devices;
use App\Modules\Identity\Actions\PendingLogin;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Decision D-03: a sign-in from a 4th device is refused and the user sees their active
 * devices. Every factor has already passed, so they may sign one out and continue.
 */
final class DeviceLimitController
{
    use SignsIn;

    public function show(Request $request, Devices $devices): View|RedirectResponse
    {
        $user = PendingLogin::user($request->session(), PendingLogin::DEVICES);

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $active = $devices->active($user);

        return view('identity::auth.device-limit', [
            'devices' => $active,
            'limit' => Devices::limit(),
            'canContinue' => $active->count() < Devices::limit(),
        ]);
    }

    public function destroy(Request $request, Devices $devices, string $device): RedirectResponse
    {
        $user = PendingLogin::user($request->session(), PendingLogin::DEVICES);

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        try {
            $devices->revoke($user, $device);
        } catch (IdentityException) {
            // Not this account's device, or already signed out: nothing to do.
        }

        return redirect()->route('login.devices');
    }

    public function continue(Request $request, CompleteLogin $complete): RedirectResponse
    {
        $user = PendingLogin::user($request->session(), PendingLogin::DEVICES);

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        if (! $this->signIn($request, $user, PendingLogin::method($request->session()), $complete)) {
            return redirect()->route('login.devices');
        }

        return redirect()->intended(route('account'));
    }
}
