<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Modules\Creators\Actions\CompleteCreatorKyc;
use App\Modules\Creators\Actions\StartCreatorKyc;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Creator onboarding boards 3–4 (ID document, selfie liveness: steps 2–3 of 4) and
 * the creator's side of board 8 (Action needed). The capture itself happens on the
 * provider's hosted page (decision D-11). Only the signed-in account's own checks.
 */
final class KycController
{
    /** Shown on board 8 for each reason the provider gives. */
    private const array FIX_MESSAGES = [
        'document_unreadable' => 'Your ID photo was too blurry to read. Everything else is fine.',
        'document_expired' => 'That ID has expired. Use one that is still valid.',
        'liveness_failed' => 'The selfie check did not finish. Try again somewhere well lit.',
        'age_unconfirmed' => 'We could not read the date of birth on your ID. Retake the photo.',
    ];

    public function show(Request $request): View|RedirectResponse
    {
        $creator = $this->creator($request);

        if (! $creator instanceof Creator) {
            return redirect()->route('creator.start');
        }

        if (! $creator->hasProfile()) {
            return redirect()->route('creator.profile');
        }

        $latest = $creator->latestKyc();

        return view('creators::kyc', [
            'creator' => $creator,
            'latest' => $latest,
            'canStart' => StartCreatorKyc::canStart($creator),
            // Board 8: the last check failed for a reason a retake can fix.
            'fix' => $latest?->status === VerificationStatus::Failed && $creator->kyc_status !== KycStatus::Rejected
                ? (self::FIX_MESSAGES[(string) $latest->failure_reason] ?? 'We could not read your ID or selfie. Try again.')
                : null,
        ]);
    }

    public function start(Request $request, StartCreatorKyc $start): RedirectResponse
    {
        $creator = $this->creator($request);

        if (! $creator instanceof Creator) {
            return redirect()->route('creator.start');
        }

        try {
            $url = $start->handle($creator, route('creator.kyc.return'));
        } catch (CreatorException $e) {
            return redirect()->route($e->field === 'profile' ? 'creator.profile' : 'creator.kyc')->withErrors([$e->field => $e->getMessage()]);
        }

        // The provider's hosted capture; it sends the creator back to the return route.
        return redirect()->away($url);
    }

    public function return(Request $request, CompleteCreatorKyc $complete): RedirectResponse
    {
        $creator = $this->creator($request);

        if (! $creator instanceof Creator) {
            return redirect()->route('creator.start');
        }

        try {
            $status = $complete->handle($creator, $request->string('ref')->toString());
        } catch (CreatorException $e) {
            return redirect()->route('creator.kyc')->withErrors([$e->field => $e->getMessage()]);
        }

        $message = match ($status) {
            KycStatus::Review => 'ID and selfie matched. Next: where should we send your money?',
            KycStatus::Approved => 'Your new ID is verified.',
            KycStatus::Pending, KycStatus::NotStarted => 'We are still checking your ID. This usually takes a few minutes.',
            KycStatus::ActionNeeded => 'One thing to fix before we can continue.',
            KycStatus::Rejected => 'We could not verify you.',
        };

        return redirect()->route('creator.kyc')->with('status', $message);
    }

    private function creator(Request $request): ?Creator
    {
        $id = $request->user()?->getAuthIdentifier();
        abort_unless(is_string($id), 403);

        return Creator::query()->where('user_id', $id)->first();
    }
}
