<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\CompleteLogin;
use App\Modules\Identity\Actions\Passkeys;
use App\Modules\Identity\Exceptions\IdentityException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;

/**
 * "Log in with passkey": no email needed, the device offers its passkeys for this site.
 */
final class PasskeyLoginController
{
    use SignsIn;

    public function options(AssertionRequest $request): Responsable
    {
        return $request->secureLogin()->toVerify(null);
    }

    public function store(Request $request, Passkeys $passkeys, CompleteLogin $complete): JsonResponse
    {
        try {
            $user = $passkeys->assert($request);
        } catch (IdentityException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $this->signIn($request, $user, 'passkey', $complete)) {
            return response()->json([
                'message' => 'This account is already signed in on the maximum number of devices.',
                'redirect' => route('login.devices'),
            ], 409);
        }

        return response()->json(['redirect' => redirect()->intended(route('account'))->getTargetUrl()]);
    }
}
