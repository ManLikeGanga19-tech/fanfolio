<?php

declare(strict_types=1);

namespace App\Modules\Creators\Support;

use App\Modules\Creators\Actions\RevealApplicantDob;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKyc;
use App\Modules\Creators\Models\PayoutMethod;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use Carbon\CarbonImmutable;

/**
 * What the approvals board (canvas AdminApprovals) says about one application: the
 * status pill in the list and the checklist in the detail pane. Built only from the
 * provider's stored result and the payout method's name match, so it shows no
 * personal data (rule 13). The provider interface has no face-match score or sanctions
 * screening yet; those lines appear when the KYC provider is chosen.
 */
final readonly class ApplicationChecks
{
    private const array PROVIDER_REASONS = [
        'name_mismatch' => 'the name on the ID differs from the account',
        'document_unclear' => 'the document was hard to read',
    ];

    private function __construct(
        public ?CreatorKyc $check,
        public ?PayoutMethod $payout,
        public bool $running,
    ) {}

    public static function of(Creator $creator): self
    {
        return new self(
            RevealApplicantDob::verifiedCheck($creator),
            $creator->payoutMethod(),
            $creator->latestKyc()?->status === VerificationStatus::Pending,
        );
    }

    /**
     * @return array{text: string, tone: 'ok'|'warning'|'danger'|'muted'}
     */
    public function pill(): array
    {
        return match (true) {
            $this->running => ['text' => 'Checks running', 'tone' => 'muted'],
            ! $this->check instanceof CreatorKyc || ! $this->check->liveness_passed => ['text' => 'Liveness failed', 'tone' => 'danger'],
            $this->check->suspected_fraud => ['text' => 'Possible fraud', 'tone' => 'danger'],
            ! $this->payoutNameMatches() || $this->check->failure_reason === 'name_mismatch' => ['text' => 'Name mismatch', 'tone' => 'warning'],
            $this->check->status === VerificationStatus::Review => ['text' => 'Provider flagged', 'tone' => 'warning'],
            default => ['text' => 'All checks passed', 'tone' => 'ok'],
        };
    }

    /**
     * @return list<array{text: string, tone: 'ok'|'warning'|'danger'}>
     */
    public function lines(): array
    {
        $check = $this->check;

        if (! $check instanceof CreatorKyc) {
            return [['text' => 'No ID check the provider passed', 'tone' => 'danger']];
        }

        $lines = [
            $check->status === VerificationStatus::Passed
                ? ['text' => 'Document checked by the verification partner', 'tone' => 'ok']
                : ['text' => 'Partner asked for a closer look: '.$this->providerReason($check->failure_reason), 'tone' => 'warning'],
            $check->liveness_passed
                ? ['text' => 'Liveness passed, selfie matches the ID', 'tone' => 'ok']
                : ['text' => 'Liveness not passed', 'tone' => 'danger'],
            $check->age_over_18
                ? ['text' => 'Age 18+', 'tone' => 'ok']
                : ['text' => 'Age 18+ not confirmed', 'tone' => 'danger'],
        ];

        if ($check->suspected_fraud) {
            $lines[] = ['text' => 'Partner flagged possible fraud', 'tone' => 'danger'];
        }

        if ($check->document_expires_at instanceof CarbonImmutable) {
            $lines[] = ['text' => 'ID valid until '.$check->document_expires_at->format('j M Y'), 'tone' => 'ok'];
        }

        $lines[] = match (true) {
            ! $this->payout instanceof PayoutMethod => ['text' => 'No payout method', 'tone' => 'danger'],
            $this->payout->name_matches_kyc => ['text' => 'Payout account name matches ID', 'tone' => 'ok'],
            default => ['text' => 'Payout account name does not match ID', 'tone' => 'warning'],
        };

        return $lines;
    }

    /** Whole years, from the encrypted date of birth; the date itself needs a logged reveal. */
    public function age(): ?int
    {
        if ($this->check?->dob === null) {
            return null;
        }

        return (int) CarbonImmutable::parse($this->check->dob, 'UTC')->diffInYears(CarbonImmutable::now('UTC'));
    }

    public function payoutNameMatches(): bool
    {
        return $this->payout instanceof PayoutMethod && $this->payout->name_matches_kyc;
    }

    private function providerReason(?string $reason): string
    {
        if ($reason === null) {
            return 'no reason given';
        }

        return self::PROVIDER_REASONS[$reason] ?? str_replace('_', ' ', $reason);
    }
}
