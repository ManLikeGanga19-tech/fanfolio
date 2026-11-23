<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\Data\VerificationMethod;
use App\Modules\Identity\Contracts\Data\VerificationPurpose;
use App\Modules\Identity\Contracts\Data\VerificationRequest;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Contracts\IdentityVerifier;
use App\Modules\Identity\Events\AgeVerified;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\AgeVerification;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * StartAgeVerification and CompleteAgeVerification (sign up step 3, optional).
 *
 * The result always comes from the provider, never from the browser. We keep the
 * provider reference, the outcome and the date only (rule 10).
 */
final readonly class AgeVerifications
{
    public function __construct(
        private IdentityVerifier $verifier,
        private AuditLog $audit,
    ) {}

    /**
     * StartAgeVerification: returns the provider URL to send the fan to.
     */
    public function start(User $user, VerificationMethod $method, string $returnUrl): string
    {
        if ($user->isAgeVerified()) {
            throw new IdentityException('Your age is already verified.', 'method');
        }

        $session = $this->verifier->startSession(new VerificationRequest($user->id, VerificationPurpose::FanAgeCheck, $returnUrl, $method));

        AgeVerification::query()->create([
            'user_id' => $user->id,
            'provider_ref' => $session->providerRef,
            'purpose' => VerificationPurpose::FanAgeCheck,
            'method' => $method,
            'status' => VerificationStatus::Pending,
        ]);

        $this->audit->record('identity.age_check_started', $user->id, 'user', $user->id, ['method' => $method->value]);

        return $session->redirectUrl;
    }

    /**
     * CompleteAgeVerification: asks the provider for the result of the user's own session.
     */
    public function complete(User $user, string $providerRef): VerificationStatus
    {
        return DB::transaction(function () use ($user, $providerRef): VerificationStatus {
            $check = AgeVerification::query()
                ->where('user_id', $user->id)
                ->where('provider_ref', $providerRef)
                ->lockForUpdate()
                ->first();

            if ($check === null) {
                throw new IdentityException('We could not find that check. Start again.', 'method');
            }

            // Already settled: a reload of the return page changes nothing.
            if ($check->status !== VerificationStatus::Pending && $check->status !== VerificationStatus::Review) {
                return $check->status;
            }

            $result = $this->verifier->result($providerRef);

            $status = match (true) {
                $result->isApprovedAdult() => VerificationStatus::Passed,
                $result->status === VerificationStatus::Review, $result->status === VerificationStatus::Pending => $result->status,
                default => VerificationStatus::Failed,
            };

            $check->forceFill([
                'status' => $status,
                'failure_reason' => $status === VerificationStatus::Failed ? $this->reason($result->failureReason, $result->ageOver18, $result->suspectedFraud) : null,
                'checked_at' => now(),
            ])->save();

            $this->audit->record('identity.age_check_'.$status->value, $user->id, 'age_verification', $check->id, [
                'reason' => $check->failure_reason,
            ]);

            if ($status === VerificationStatus::Passed) {
                $user->forceFill(['age_verified_at' => now()])->save();
                AgeVerified::dispatch($user->id);
            }

            return $status;
        });
    }

    private function reason(?string $providerReason, bool $ageOver18, bool $suspectedFraud): string
    {
        return match (true) {
            $suspectedFraud => 'suspected_fraud',
            ! $ageOver18 && $providerReason === 'under_18' => 'under_18',
            default => substr($providerReason ?? 'not_verified', 0, 50),
        };
    }
}
