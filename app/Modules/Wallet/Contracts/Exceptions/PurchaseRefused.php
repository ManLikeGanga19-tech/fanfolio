<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Exceptions;

use RuntimeException;

/**
 * A purchase was refused before any money moved. $problem is the stable code the API
 * returns. Insufficient credits, a busy wallet and a reused key come as LedgerException.
 */
final class PurchaseRefused extends RuntimeException
{
    public const string CREATOR_UNAVAILABLE = 'creator_unavailable';

    public const string WALLET_FROZEN = 'wallet_frozen';

    public const string INVALID = 'invalid_purchase';

    private function __construct(string $message, public readonly string $problem)
    {
        parent::__construct($message);
    }

    public static function creatorUnavailable(): self
    {
        return new self('This creator cannot take payments right now.', self::CREATOR_UNAVAILABLE);
    }

    public static function walletFrozen(): self
    {
        return new self('Your wallet is on hold while we look into a payment. Contact support.', self::WALLET_FROZEN);
    }

    public static function invalid(string $message): self
    {
        return new self($message, self::INVALID);
    }
}
