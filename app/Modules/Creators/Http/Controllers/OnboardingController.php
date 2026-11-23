<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Modules\Creators\Actions\SaveProfileBasics;
use App\Modules\Creators\Actions\StartCreatorApplication;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Http\Requests\ProfileBasicsRequest;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Support\HandleRules;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Creator onboarding boards 1–2: Become a creator, Profile basics (step 1 of 4).
 * Steps 2–3 (ID and selfie) are in KycController.
 * The signed-in account is the only creator these routes ever touch.
 */
final class OnboardingController
{
    public function start(Request $request): View|RedirectResponse
    {
        $creator = $this->creator($request);

        if ($creator instanceof Creator) {
            // Past onboarding (submitted, approved, suspended): the status page says where they stand.
            return redirect()->route($creator->status === CreatorStatus::Onboarding ? 'creator.profile' : 'creator.status');
        }

        return view('creators::start');
    }

    public function begin(Request $request, StartCreatorApplication $start): RedirectResponse
    {
        try {
            $start->handle($this->userId($request));
        } catch (CreatorException $e) {
            // SEC-04: creators need MFA. Send them to set it up, then back here.
            $request->session()->put('url.intended', route('creator.start'));

            return redirect()->route('account.security')->with('status', $e->getMessage());
        }

        return redirect()->route('creator.profile');
    }

    public function profile(Request $request): View|RedirectResponse
    {
        $creator = $this->creator($request);

        if (! $creator instanceof Creator) {
            return redirect()->route('creator.start');
        }

        return view('creators::profile', [
            'creator' => $creator,
            'categories' => config()->array('fanfolio.creators.categories'),
        ]);
    }

    public function saveProfile(ProfileBasicsRequest $request, SaveProfileBasics $save): RedirectResponse
    {
        $creator = $this->creator($request);

        if (! $creator instanceof Creator) {
            return redirect()->route('creator.start');
        }

        try {
            $save->handle(
                $creator,
                $request->string('display_name')->toString(),
                $request->string('handle')->toString(),
                $request->string('category')->toString(),
                $request->boolean('is_adult'),
            );
        } catch (CreatorException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        if ($request->boolean('exit')) {
            return redirect()->route('account')->with('status', 'Saved. Come back any time to finish becoming a creator.');
        }

        return redirect()->route('creator.kyc')->with('status', 'Saved. Next: verify your identity.');
    }

    /**
     * "Available" next to the handle field while typing. The save checks again.
     */
    public function handleCheck(Request $request, SaveProfileBasics $save): JsonResponse
    {
        $handle = HandleRules::normalise($request->string('handle')->toString());
        $problem = HandleRules::problem($handle);

        if ($problem !== null) {
            return response()->json(['available' => false, 'message' => $problem]);
        }

        $available = $save->available($handle, $this->creator($request));

        return response()->json([
            'available' => $available,
            'message' => $available ? 'Available' : 'This handle is taken.',
        ]);
    }

    private function creator(Request $request): ?Creator
    {
        return Creator::query()->where('user_id', $this->userId($request))->first();
    }

    private function userId(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();
        abort_unless(is_string($id), 403);

        return $id;
    }
}
