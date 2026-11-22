<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use App\Support\Money\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Units of $to per one unit of $from, scaled by 1_000_000 (micro-units) to avoid floats.
 * Assumes both currencies use two minor-unit digits (true for USD and KES).
 */
final readonly class ExchangeRate
{
    public const int SCALE = 1_000_000;

    public function __construct(
        public string $from,
        public string $to,
        public int $rateMicros,
        public DateTimeImmutable $asOf,
    ) {}

    /**
     * Convert for display, rounding down.
     */
    public function convert(Money $money): Money
    {
        if ($money->currency !== $this->from) {
            throw new InvalidArgumentException("Rate converts {$this->from}, got {$money->currency}.");
        }

        return Money::of(intdiv($money->amountMinor * $this->rateMicros, self::SCALE), $this->to);
    }
}
