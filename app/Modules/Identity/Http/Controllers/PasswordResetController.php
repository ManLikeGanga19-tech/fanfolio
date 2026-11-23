<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\ResetPassword;
use App\Modules\Identity\Exceptions\IdentityException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot password?" from the log in screen.
 */
final class PasswordResetController
{
    public const string SENT = 'If that email has an account, we have sent a link to reset the password.';

    public function create(): View
    {
        return view('identity::auth.forgot-password');
    }

    public function store(Request $request, ResetPassword $reset): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);

        $reset->sendLink($request->string('email')->toString());

        // The same reply whether or not the email has an account.
        return redirect()->route('password.request')->with('status', self::SENT);
    }

    public function edit(Request $request, string $token): View
    {
        return view('identity::auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(Request $request, ResetPassword $reset): RedirectResponse
    {
        /** @var array{token: string, email: string, password: string} $input */
        $input = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200', 'confirmed'],
        ]);

        try {
            $reset->reset($input['email'], $input['token'], $input['password']);
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('login')->with('status', 'Your password is changed and every device is signed out. Log in with your new password.');
    }
}
