<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\PayoutMethod;
use Illuminate\Support\Facades\DB;

/**
 * Board 6 · Review & submit. Needs the page, an ID and selfie the provider passed, a
 * payout method and both statements (Creator Terms with the 20% fee, and the
 * co-performer statement worded per decision D-02). Staff then review (D-10).
 * Submitting never lets anyone publish or earn: only staff approval does.
 */
final readonly class SubmitApplication
{
    public function __construct(private AuditLog $audit) {}

    public function handle(Creator $creator, bool $acceptsTerms, bool $confirmsCoPerformers): Creator
    {
        if (! $acceptsTerms) {
            throw new CreatorException('Agree to the Creator Terms and the 20% platform fee.', 'terms');
        }

        if (! $confirmsCoPerformers) {
            throw new CreatorException('Confirm that everyone in your content is 18+ and will verify and consent.', 'co_performers');
        }

        return DB::transaction(function () use ($creator): Creator {
            $creator = Creator::query()->whereKey($creator->id)->lockForUpdate()->firstOrFail();

            if ($creator->status !== CreatorStatus::Onboarding) {
                // Submitting twice (a double tap, a reload) is harmless.
                if ($creator->status === CreatorStatus::Submitted) {
                    return $creator;
                }

                throw new CreatorException('This application cannot be submitted.', 'submit');
            }

            if (! $creator->hasProfile()) {
                throw new CreatorException('Set up your page first.', 'profile');
            }

            if ($creator->kyc_status !== KycStatus::Review) {
                throw new CreatorException('Verify your identity first.', 'kyc');
            }

            if (! $creator->payoutMethod() instanceof PayoutMethod) {
                throw new CreatorException('Add a payout method first.', 'payout');
            }

            $creator->forceFill([
                'status' => CreatorStatus::Submitted,
                'submitted_at' => now(),
                'terms_accepted_at' => now(),
            ])->save();

            $this->audit->record('creator.submitted', $creator->user_id, 'creator', $creator->id, [
                'fee_bps' => config()->integer('fanfolio.money.platform_fee_bps'),
            ]);

            return $creator;
        });
    }
}
