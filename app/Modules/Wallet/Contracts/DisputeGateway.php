<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts;

use RuntimeException;

/**
 * Answers the processor's chargeback cases (the top-up processor is not chosen). The
 * processor tells us the outcome later through a signed webhook (dispute won or lost).
 */
interface DisputeGateway
{
    /**
     * Contest the case with evidence gathered from our logs. Idempotent on the case.
     *
     * @param  list<string>  $evidence
     *
     * @throws RuntimeException when the processor refuses or cannot be reached
     */
    public function submitEvidence(string $caseRef, array $evidence): void;

    /**
     * Do not contest the case: the card holder keeps the refund. Idempotent on the case.
     *
     * @throws RuntimeException when the processor refuses or cannot be reached
     */
    public function accept(string $caseRef): void;
}
