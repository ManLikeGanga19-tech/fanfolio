<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Events\CreatorKycFailed;
use App\Modules\Creators\Events\CreatorKycPassed;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKyc;
use App\Modules\Identity\Contracts\Data\VerificationResult;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Contracts\IdentityVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The creator is back from the provider's hosted page. The result is always fetched
 * from the provider, never read from the browser, and only for a check that belongs to
 * this creator. We keep the reference, the result and the date (rule 10).
 *
 * - Passed: "Manual review" by staff (decision D-10), or a renewal of the same person.
 * - Under 18 or suspected fraud: rejected, with CreatorKycFailed for Trust & Safety (rule 8).
 * - Anything else that failed (blurry photo, liveness not finished): "Action needed", retake.
 */
final readonly class CompleteCreatorKyc
{
    public function __construct(
        private IdentityVerifier $verifier,
        private AuditLog $audit,
    ) {}

    public function handle(Creator $creator, string $providerRef): KycStatus
    {
        return DB::transaction(function () use ($creator, $providerRef): KycStatus {
            $creator = Creator::query()->whereKey($creator->id)->lockForUpdate()->firstOrFail();

            $check = $creator->kycChecks()->where('provider_ref', $providerRef)->lockForUpdate()->first();

            if (! $check instanceof CreatorKyc) {
                throw new CreatorException('We could not find that check. Start again.', 'kyc');
            }

            // Settled already, or replaced by a newer check: a reload changes nothing.
            if ($check->status !== VerificationStatus::Pending || $creator->latestKyc()?->id !== $check->id) {
                return $creator->kyc_status;
            }

            $result = $this->verifier->result($providerRef);

            if ($result->status === VerificationStatus::Pending) {
                return $creator->kyc_status;
            }

            [$status, $reason] = $this->decide($result);
            $previous = $this->lastPassed($creator, $check);

            $this->record($check, $result, $status, $reason);

            if ($status === KycStatus::Review && $this->isRenewal($creator, $check, $previous)) {
                $status = KycStatus::Approved;
            }

            // A renewal that needs a retake leaves the current, still valid ID in place.
            $keep = $creator->kyc_status === KycStatus::Approved && $status === KycStatus::ActionNeeded;

            $creator->forceFill([
                'kyc_status' => $keep ? KycStatus::Approved : $status,
                'id_expires_at' => $this->counts($status) ? $check->document_expires_at?->endOfDay() : $creator->id_expires_at,
                'id_expiry_reminded_at' => $this->counts($status) ? null : $creator->id_expiry_reminded_at,
            ])->save();

            $action = $status === KycStatus::Approved ? 'creator.kyc_renewed' : 'creator.kyc_'.$status->value;
            $this->audit->record($action, $creator->user_id, 'creator_kyc', $check->id, [
                'reason' => $reason,
                'id_type' => $check->id_type,
            ]);

            if ($status === KycStatus::Review && $result->status === VerificationStatus::Passed) {
                CreatorKycPassed::dispatch($creator->id, $creator->user_id);
            }

            if ($status === KycStatus::Rejected) {
                CreatorKycFailed::dispatch($creator->id, $creator->user_id, (string) $reason);
            }

            return $status;
        });
    }

    /**
     * @return array{KycStatus, ?string}
     */
    private function decide(VerificationResult $result): array
    {
        $dobUnder18 = $result->dateOfBirth instanceof \DateTimeImmutable
            && CarbonImmutable::instance($result->dateOfBirth)->addYears(18)->isFuture();

        return match (true) {
            $result->suspectedFraud => [KycStatus::Rejected, 'suspected_fraud'],
            $dobUnder18, $result->failureReason === 'under_18',
            $result->status === VerificationStatus::Passed && ! $result->ageOver18 => [KycStatus::Rejected, 'under_18'],
            $result->status === VerificationStatus::Failed => [KycStatus::ActionNeeded, substr($result->failureReason ?? 'not_verified', 0, 50)],
            ! $result->livenessPassed => [KycStatus::ActionNeeded, 'liveness_failed'],
            ! $result->ageOver18 => [KycStatus::ActionNeeded, 'age_unconfirmed'],
            $result->documentExpiresAt instanceof \DateTimeImmutable && CarbonImmutable::instance($result->documentExpiresAt)->endOfDay()->isPast() => [KycStatus::ActionNeeded, 'document_expired'],
            // Provider passed or wants a closer look (for example a name mismatch): staff decide.
            default => [KycStatus::Review, $result->failureReason === null ? null : substr($result->failureReason, 0, 50)],
        };
    }

    private function record(CreatorKyc $check, VerificationResult $result, KycStatus $status, ?string $reason): void
    {
        $last4 = $result->documentLast4 === null ? null : substr($result->documentLast4, -4);

        $check->forceFill([
            'status' => match ($status) {
                KycStatus::Rejected, KycStatus::ActionNeeded => VerificationStatus::Failed,
                default => $result->status,
            },
            'liveness_passed' => $result->livenessPassed,
            'age_over_18' => $result->ageOver18 && $status !== KycStatus::Rejected,
            'id_type' => $result->documentType === null ? null : substr($result->documentType, 0, 30),
            'document_last4' => $last4,
            'document_expires_at' => $result->documentExpiresAt instanceof \DateTimeImmutable ? CarbonImmutable::instance($result->documentExpiresAt)->startOfDay() : null,
            'legal_name' => $result->legalName,
            'dob' => $result->dateOfBirth?->format('Y-m-d'),
            'failure_reason' => $reason,
            'suspected_fraud' => $result->suspectedFraud,
            'checked_at' => now(),
        ])->save();
    }

    /**
     * An approved creator verifying a new ID before the old one expires stays approved
     * when the provider passed it outright and it is the same person (name and date of
     * birth). Anything else goes back to staff, and publishing stops until they decide.
     */
    private function isRenewal(Creator $creator, CreatorKyc $check, ?CreatorKyc $previous): bool
    {
        return $creator->kyc_status === KycStatus::Approved
            && $check->status === VerificationStatus::Passed
            && $check->failure_reason === null
            && $previous instanceof CreatorKyc
            && $previous->legal_name !== null
            && $previous->dob !== null
            && hash_equals($previous->legal_name, (string) $check->legal_name)
            && hash_equals($previous->dob, (string) $check->dob);
    }

    private function lastPassed(Creator $creator, CreatorKyc $except): ?CreatorKyc
    {
        return $creator->kycChecks()
            ->whereKeyNot($except->id)
            ->where('status', VerificationStatus::Passed)
            ->latest('checked_at')
            ->first();
    }

    private function counts(KycStatus $status): bool
    {
        return $status === KycStatus::Review || $status === KycStatus::Approved;
    }
}
