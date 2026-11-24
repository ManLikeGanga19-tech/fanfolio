<?php

declare(strict_types=1);

namespace App\Modules\Creators\Contracts;

use App\Modules\Creators\Contracts\Data\PayoutDestination;

/**
 * Creators keeps payout methods (encrypted, rule 11); Wallet reads them through this
 * contract. The account details leave Creators only in `instructionFor`, at the moment
 * a payout is sent, and must never be logged or stored by the caller.
 */
interface PayoutDestinations
{
    /**
     * Active methods, the default first.
     *
     * @return list<PayoutDestination>
     */
    public function forUser(string $userId): array;

    /** One active method of this creator, or null. */
    public function find(string $userId, string $methodId): ?PayoutDestination;

    /**
     * The name on the account and the rail details, decrypted, for the payout gateway.
     *
     * @return array{account_name: string, details: array<string, string>}|null
     */
    public function instructionFor(string $userId, string $methodId): ?array;
}
