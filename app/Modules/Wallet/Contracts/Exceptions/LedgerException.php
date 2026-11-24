<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Exceptions;

use RuntimeException;

/**
 * The ledger refused a transaction. $code is the stable problem code the API returns
 * (spec "API contracts": RFC 9457 with a stable code).
 */
final class LedgerException extends RuntimeException
{
    public const string INSUFFICIENT = 'insufficient_credits';

    public const string BUSY = 'wallet_busy';

    public const string KEY_REUSED = 'idempotency_key_reused';

    private function __construct(string $message, public readonly string $problem)
    {
        parent::__construct($message);
    }

    public static function insufficient(string $accountKey): self
    {
        return new self("Not enough funds in {$accountKey}.", self::INSUFFICIENT);
    }

    public static function busy(): self
    {
        return new self('Another payment on this wallet is in progress. Try again.', self::BUSY);
    }

    public static function keyReused(): self
    {
        return new self('This idempotency key was already used for a different transaction.', self::KEY_REUSED);
    }
}
