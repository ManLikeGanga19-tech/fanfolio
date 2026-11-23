<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\CompleteLogin;
use App\Modules\Identity\Actions\RecoveryCodes;
use App\Modules\Identity\Actions\RegisterFan;
use App\Modules\Identity\Actions\RegisterFanWithPasskey;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Http\Requests\PasskeySignUpRequest;
use App\Modules\Identity\Http\Requests\SignUpRequest;
use App\Modules\Identity\Support\DeviceLabel;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class SignUpController
{
    use SignsIn;

    /** Session flag: the recovery codes page continues to the phone step. */
    public const string CONTINUE_SIGNUP = 'identity.signup_continues';

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

        $this->signIn($request, $user, 'signup', $complete);

        return redirect()->route('signup.phone');
    }

    /**
     * "Continue with a passkey", step one: check the account fields, then ask the
     * device to make a passkey.
     */
    public function passkeyOptions(PasskeySignUpRequest $request, RegisterFanWithPasskey $register): Responsable
    {
        return $register->options(
            $request->session(),
            $request->string('email')->toString(),
            $request->string('username')->toString(),
        );
    }

    /**
     * Step two: the device returns the new passkey. The account is created, signed in,
     * and shown its recovery codes before the phone step, since it has no password.
     */
    public function storePasskey(Request $request, RegisterFanWithPasskey $register, RecoveryCodes $recoveryCodes, CompleteLogin $complete): JsonResponse
    {
        /** @var array<string, mixed> $attestation */
        $attestation = $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'response' => ['required', 'array'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.attestationObject' => ['required', 'string'],
            'type' => ['required', 'string'],
        ]);

        try {
            $user = $register->complete($request->session(), $attestation, DeviceLabel::fromUserAgent($request->userAgent()));
        } catch (IdentityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $codes = $recoveryCodes->issue($user);

        // A brand-new account has no other devices, so this always signs in.
        $this->signIn($request, $user, 'signup_passkey', $complete);

        $request->session()->flash('recovery_codes', $codes);
        $request->session()->put(self::CONTINUE_SIGNUP, true);

        return response()->json(['redirect' => route('account.security.recovery-codes')]);
    }
}
