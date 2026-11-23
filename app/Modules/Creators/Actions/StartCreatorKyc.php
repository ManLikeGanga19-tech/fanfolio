<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKyc;
use App\Modules\Identity\Contracts\Data\VerificationMethod;
use App\Modules\Identity\Contracts\Data\VerificationPurpose;
use App\Modules\Identity\Contracts\Data\VerificationRequest;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Contracts\IdentityVerifier;
use Illuminate\Support\Facades\DB;

/**
 * Steps 2–3 of 4: ID document and selfie liveness, on the provider's hosted page.
 * Images go straight to the provider and never through or into FanFolio (rule 10).
 * Returns the provider URL to send the creator to.
 */
final readonly class StartCreatorKyc
{
    /** Days before expiry when a creator may verify a new ID. */
    public const int RENEW_WITHIN_DAYS = 30;

    public function __construct(
        private IdentityVerifier $verifier,
        private AuditLog $audit,
    ) {}

    public function handle(Creator $creator, string $returnUrl): string
    {
        if (! $creator->hasProfile()) {
            throw new CreatorException('Set up your page first.', 'profile');
        }

        if (! self::canStart($creator)) {
            throw new CreatorException(match ($creator->kyc_status) {
                KycStatus::Rejected => 'We could not verify you. Contact support if you think this is a mistake.',
                default => 'Your ID and selfie are already checked.',
            }, 'kyc');
        }

        $session = $this->verifier->startSession(new VerificationRequest($creator->id, VerificationPurpose::CreatorKyc, $returnUrl, VerificationMethod::Document));

        DB::transaction(function () use ($creator, $session): void {
            CreatorKyc::query()->create([
                'creator_id' => $creator->id,
                'provider_ref' => $session->providerRef,
                'status' => VerificationStatus::Pending,
            ]);

            // A creator who is already approved keeps their status while renewing.
            if ($creator->kyc_status !== KycStatus::Approved) {
                $creator->forceFill(['kyc_status' => KycStatus::Pending])->save();
            }

            $this->audit->record('creator.kyc_started', $creator->user_id, 'creator', $creator->id);
        });

        return $session->redirectUrl;
    }

    public static function canStart(Creator $creator): bool
    {
        return match ($creator->kyc_status) {
            KycStatus::NotStarted, KycStatus::Pending, KycStatus::ActionNeeded => true,
            KycStatus::Approved => $creator->id_expires_at !== null && $creator->id_expires_at->lte(now()->addDays(self::RENEW_WITHIN_DAYS)),
            KycStatus::Review, KycStatus::Rejected => false,
        };
    }
}
