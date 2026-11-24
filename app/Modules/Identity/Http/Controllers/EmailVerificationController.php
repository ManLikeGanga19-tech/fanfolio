<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\SendEmailCode;
use App\Modules\Identity\Actions\VerifyEmail;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\EmailVerification;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Sign up step 2 of 3: "Verify your email" (decision D-12, in place of the phone
 * code). The first code is sent at sign-up; this page enters it or asks for another.
 */
final class EmailVerificationController
{
    public function show(Request $request, SendEmailCode $codes): View|RedirectResponse
    {
        $user = $this->user($request);

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('signup.age');
        }

        $pending = EmailVerification::query()
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->exists();

        $wait = $codes->secondsUntilResend($user);

        return view('identity::auth.verify-email', [
            'email' => $user->email,
            'pending' => $pending,
            'resendIn' => SendEmailCode::clock($wait),
            'canResend' => $wait === 0,
        ]);
    }

    public function send(Request $request, SendEmailCode $codes): RedirectResponse
    {
        try {
            $codes->handle($this->user($request));
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('signup.email')->with('status', 'We sent a new code.');
    }

    public function verify(Request $request, VerifyEmail $verify): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ], [
            'code.required' => 'Enter the 6-digit code.',
            'code.regex' => 'Enter the 6-digit code.',
        ]);

        try {
            $verify->handle($this->user($request), $request->string('code')->toString());
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        // On to step 3, the optional 18+ check.
        return redirect()->route('signup.age')->with('status', 'Email verified.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
