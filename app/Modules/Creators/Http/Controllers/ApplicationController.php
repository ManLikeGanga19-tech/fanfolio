<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Modules\Creators\Actions\AddPayoutMethod;
use App\Modules\Creators\Actions\SubmitApplication;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\PayoutMethod;
use App\Modules\Wallet\Contracts\Data\PayoutRail;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Creator onboarding boards 5 (Payout method, step 4 of 4) and 6 (Review & submit).
 * Only the signed-in account's own application.
 */
final class ApplicationController
{
    public function payout(Request $request): View|RedirectResponse
    {
        $creator = $this->creator($request);

        if (($redirect = $this->needsEarlierStep($creator, payout: false)) instanceof RedirectResponse) {
            return $redirect;
        }

        /** @var Creator $creator */
        return view('creators::payout', [
            'creator' => $creator,
            'current' => $creator->payoutMethod(),
            'legalName' => $creator->verifiedLegalName(),
        ]);
    }

    public function savePayout(Request $request, AddPayoutMethod $add): RedirectResponse
    {
        $creator = $this->creator($request);

        if (($redirect = $this->needsEarlierStep($creator, payout: false)) instanceof RedirectResponse) {
            return $redirect;
        }

        $rail = PayoutRail::tryFrom($request->string('rail')->toString());

        if (! $rail instanceof PayoutRail) {
            throw ValidationException::withMessages(['rail' => 'Choose where we should send your money.']);
        }

        try {
            /** @var Creator $creator */
            $input = [];
            foreach (['bank_name', 'account_number', 'wallet_address', 'paxum_email', 'account_name'] as $field) {
                $input[$field] = $request->string($field)->toString();
            }

            $method = $add->handle($creator, $rail, $input);
        } catch (CreatorException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('creator.review')->with('status', $method->name_matches_kyc
            ? 'Payout method saved.'
            : 'Payout method saved. The name on the account does not match your ID, so our team will check it before your first payout.');
    }

    public function review(Request $request): View|RedirectResponse
    {
        $creator = $this->creator($request);

        // Submitted, approved or suspended: board 7 and the other status screens.
        if ($creator instanceof Creator && $creator->status !== CreatorStatus::Onboarding) {
            return redirect()->route('creator.status');
        }

        if (($redirect = $this->needsEarlierStep($creator, payout: true)) instanceof RedirectResponse) {
            return $redirect;
        }

        /** @var Creator $creator */
        return view('creators::review', [
            'creator' => $creator,
            'kyc' => $creator->latestKyc(),
            'payout' => $creator->payoutMethod(),
        ]);
    }

    public function submit(Request $request, SubmitApplication $submit): RedirectResponse
    {
        $creator = $this->creator($request);

        if (! $creator instanceof Creator) {
            return redirect()->route('creator.start');
        }

        try {
            $submit->handle($creator, $request->boolean('terms'), $request->boolean('co_performers'));
        } catch (CreatorException $e) {
            $step = ['profile' => 'creator.profile', 'kyc' => 'creator.kyc', 'payout' => 'creator.payout'][$e->field] ?? null;

            if ($step !== null) {
                return redirect()->route($step)->withErrors([$e->field => $e->getMessage()]);
            }

            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }

        return redirect()->route('creator.status')->with('status', 'Submitted. We\'ll let you know as soon as you\'re approved.');
    }

    /** Sends the creator to the first unfinished step before this one. */
    private function needsEarlierStep(?Creator $creator, bool $payout): ?RedirectResponse
    {
        return match (true) {
            ! $creator instanceof Creator => redirect()->route('creator.start'),
            ! $creator->hasProfile() => redirect()->route('creator.profile'),
            ! in_array($creator->kyc_status, [KycStatus::Review, KycStatus::Approved], true) => redirect()->route('creator.kyc'),
            $payout && ! $creator->payoutMethod() instanceof PayoutMethod => redirect()->route('creator.payout'),
            default => null,
        };
    }

    private function creator(Request $request): ?Creator
    {
        $id = $request->user()?->getAuthIdentifier();
        abort_unless(is_string($id), 403);

        return Creator::query()->where('user_id', $id)->first();
    }
}
