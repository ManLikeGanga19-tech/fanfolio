<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\CompleteLogin;
use App\Modules\Identity\Actions\RegisterFan;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Http\Requests\SignUpRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class SignUpController
{
    public function show(): View
    {
        return view('identity::auth.signup');
    }

    public function store(SignUpRequest $request, RegisterFan $register, CompleteLogin $complete): RedirectResponse
    {
        try {
            $user = $register->handle(
                $request->string('email')->toString(),
                $request->string('username')->toString(),
                $request->string('password')->toString(),
            );
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $complete->handle($user, 'signup');

        return redirect()->route('signup.phone');
    }
}
