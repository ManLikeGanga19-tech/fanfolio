<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Board 7 · In review, and board 8 · Action needed when staff send the application
 * back. Also where Studio sends anyone it does not let in.
 */
final class StatusController
{
    public function show(Request $request, CreatorEligibility $eligibility): View|RedirectResponse
    {
        $id = $request->user()?->getAuthIdentifier();
        abort_unless(is_string($id), 403);

        $creator = Creator::query()->where('user_id', $id)->first();

        if (! $creator instanceof Creator) {
            return redirect()->route('creator.start');
        }

        $state = match (true) {
            $creator->status === CreatorStatus::Suspended => 'suspended',
            $creator->status === CreatorStatus::Active => $eligibility->canPublish($creator->user_id) ? 'approved' : 'id_expired',
            $creator->status === CreatorStatus::Submitted => 'in_review',
            $creator->kyc_status === KycStatus::Rejected => 'rejected',
            $creator->fix_kind !== null => 'fix',
            default => 'unfinished',
        };

        return view('creators::status', [
            'creator' => $creator,
            'state' => $state,
            'kyc' => $creator->latestKyc(),
        ]);
    }
}
