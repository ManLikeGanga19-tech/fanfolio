<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\CompleteLogin;
use App\Modules\Identity\Actions\PendingLogin;
use App\Modules\Identity\Actions\RequireStepUp;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The one place a browser session is started, once every required factor passed.
 */
trait SignsIn
{
    private function signIn(Request $request, User $user, string $method, CompleteLogin $complete): void
    {
        PendingLogin::clear($request->session());

        Auth::login($user);
        $request->session()->regenerate();

        // Signing in counts as a fresh confirmation for step-up.
        RequireStepUp::confirm($request->session());

        $complete->handle($user, $method);
    }
}
