<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\AgeVerifications;
use App\Modules\Identity\Contracts\Data\VerificationMethod;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Screen "Age verification (18+, optional)": sign up step 3, also reachable later.
 */
final class AgeVerificationController
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->user($request);

        if ($user->isAgeVerified()) {
            return redirect()->route('account');
        }

        return view('identity::auth.age');
    }

    public function start(Request $request, AgeVerifications $checks): RedirectResponse
    {
        $request->validate([
            'method' => ['required', Rule::enum(VerificationMethod::class)],
        ]);

        try {
            $url = $checks->start(
                $this->user($request),
                VerificationMethod::from($request->string('method')->toString()),
                route('signup.age.return'),
            );
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        // The provider's hosted page; it sends the fan back to the return route.
        return redirect()->away($url);
    }

    public function return(Request $request, AgeVerifications $checks): RedirectResponse
    {
        $ref = $request->string('ref')->toString();

        try {
            $status = $checks->complete($this->user($request), $ref);
        } catch (IdentityException $e) {
            return redirect()->route('signup.age')->withErrors([$e->field => $e->getMessage()]);
        }

        return match ($status) {
            VerificationStatus::Passed => redirect()->route('account')->with('status', 'Your age is verified.'),
            VerificationStatus::Pending, VerificationStatus::Review => redirect()->route('account')->with('status', 'We are checking your details. This usually takes a few minutes.'),
            VerificationStatus::Failed => redirect()->route('signup.age')->withErrors(['method' => 'We could not verify your age. You can try again or keep using FanFolio with safe content only.']),
        };
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
