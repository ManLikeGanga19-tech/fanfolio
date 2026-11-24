<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\AgeVerifications;
use App\Modules\Identity\Contracts\Data\HandoffLink;
use App\Modules\Identity\Contracts\Data\VerificationMethod;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Contracts\PhoneHandoff;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\AgeVerification;
use App\Modules\Identity\Models\User;
use App\Support\DevMode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Screen "Age verification (18+, optional)": sign up step 3, also reachable later.
 *
 * Decision D-14: the ID photo or selfie is taken on a phone. A phone goes straight to
 * the provider; a computer gets a QR code (PhoneHandoff).
 */
final class AgeVerificationController
{
    private const string HANDOFF_KEY = 'identity.age_handoff';

    public function show(Request $request): View|RedirectResponse
    {
        $user = $this->user($request);

        if ($user->isAgeVerified()) {
            return redirect()->route('account');
        }

        $handoff = $request->session()->get(self::HANDOFF_KEY);
        $link = HandoffLink::fromSession($handoff);
        $pending = AgeVerification::query()
            ->where('user_id', $user->id)
            ->where('status', VerificationStatus::Pending)
            ->latest('created_at')
            ->first();

        return view('identity::auth.age', [
            'handoff' => $link instanceof HandoffLink && is_array($handoff) && $pending instanceof AgeVerification && ($handoff['ref'] ?? null) === $pending->provider_ref ? $link : null,
            'pending' => $pending,
            'devMode' => DevMode::enabled(),
        ]);
    }

    public function start(Request $request, AgeVerifications $checks, PhoneHandoff $handoff): RedirectResponse
    {
        $request->validate([
            'method' => ['required', Rule::enum(VerificationMethod::class)],
        ]);

        $user = $this->user($request);
        // Set by resources/js/kyc-capture.js; without it, assume a computer.
        $onPhone = $request->string('device')->toString() === 'phone';
        $request->session()->forget(self::HANDOFF_KEY);

        try {
            $url = $checks->start(
                $user,
                VerificationMethod::from($request->string('method')->toString()),
                $onPhone ? route('signup.age.return') : $handoff->returnUrl($user->id, route('signup.age.return')),
            );
        } catch (IdentityException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        if ($onPhone) {
            // The provider's hosted page; it sends the fan back to the return route.
            return redirect()->away($url);
        }

        $link = $handoff->issue($user->id, $url);
        $ref = AgeVerification::query()->where('user_id', $user->id)->latest('created_at')->value('provider_ref');
        $request->session()->put(self::HANDOFF_KEY, [...$link->toArray(), 'ref' => $ref]);

        return redirect()->route('signup.age');
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
