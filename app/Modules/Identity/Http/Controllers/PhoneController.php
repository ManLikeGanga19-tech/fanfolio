<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\SendPhoneCode;
use App\Modules\Identity\Actions\VerifyPhone;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\PhoneVerification;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PhoneController
{
    public function show(Request $request, SendPhoneCode $codes): View|RedirectResponse
    {
        $user = $this->user($request);

        if ($user->hasVerifiedPhone()) {
            return redirect()->route('account');
        }

        $pending = PhoneVerification::query()
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        $showCode = $pending !== null && ! $request->boolean('change');

        return view('identity::auth.phone', [
            // The screen shows a masked number: +254 7•• ••• 412.
            'maskedPhone' => $showCode ? $this->mask($pending->phone_e164) : null,
            'resendIn' => SendPhoneCode::clock($codes->secondsUntilResend($user)),
            'canResend' => $codes->secondsUntilResend($user) === 0,
        ]);
    }

    public function send(Request $request, SendPhoneCode $codes): RedirectResponse
    {
        $user = $this->user($request);

        // "Resend code" reuses the number of the latest request, so the page never holds it in full.
        $latest = $request->boolean('resend')
            ? PhoneVerification::query()->where('user_id', $user->id)->latest('created_at')->first()
            : null;

        $phone = $latest->phone_e164 ?? preg_replace('/[\s-]+/', '', $request->string('phone')->toString()) ?? '';
        $request->merge(['phone' => $phone]);

        $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
        ], [
            'phone.regex' => 'Enter the number with its country code, for example +254.',
        ]);

        try {
            $codes->handle($user, $phone);
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('signup.phone');
    }

    public function verify(Request $request, VerifyPhone $verify): RedirectResponse
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
        return redirect()->route('signup.age');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function mask(string $phone): string
    {
        return substr($phone, 0, 4).' '.substr($phone, 4, 1).'•• ••• '.substr($phone, -3);
    }
}
