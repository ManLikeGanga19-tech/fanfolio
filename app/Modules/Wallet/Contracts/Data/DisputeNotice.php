<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use DateTimeImmutable;

/**
 * The dispute fields of a chargeback event, and the case a won or lost event decides.
 */
final readonly class DisputeNotice
{
    public const array REASONS = ['not_recognised', 'not_as_described', 'fraudulent', 'duplicate', 'other'];

    public const array THREE_DS = ['passed', 'failed', 'not_used'];

    public function __construct(
        /** The processor's case number ("CB-2291"). */
        public string $caseRef,
        /** One of REASONS; anything else is stored as "other". */
        public string $reason = 'other',
        /** The card network's deadline for evidence. */
        public ?DateTimeImmutable $respondBy = null,
        /** One of THREE_DS, or null when the processor did not say. */
        public ?string $threeDs = null,
    ) {}

    /**
     * @return array{reason: string, respond_by: string|null, three_ds: string|null}
     */
    public function details(): array
    {
        return [
            'reason' => in_array($this->reason, self::REASONS, true) ? $this->reason : 'other',
            'respond_by' => $this->respondBy?->format(DATE_ATOM),
            'three_ds' => in_array($this->threeDs, self::THREE_DS, true) ? $this->threeDs : null,
        ];
    }
}
