<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * An amount of money in integer minor units plus an ISO 4217 currency code.
 *
 * Wallet credits are stored as USD cents: 100 credits = $1, so 1 credit = 1 USD minor unit.
 * Floats are never used for money.
 */
final readonly class Money implements JsonSerializable
{
    public const string CREDIT_CURRENCY = 'USD';

    private function __construct(
        public int $amountMinor,
        public string $currency,
    ) {}

    public static function of(int $amountMinor, string $currency): self
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Invalid currency code [{$currency}].");
        }

        return new self($amountMinor, $currency);
    }

    public static function credits(int $credits): self
    {
        return new self($credits, self::CREDIT_CURRENCY);
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->amountMinor, $this->currency);
    }

    /**
     * The share of this amount at the given basis points (10_000 = 100%), rounded down.
     */
    public function shareBps(int $basisPoints): self
    {
        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw new InvalidArgumentException("Basis points must be between 0 and 10000, got {$basisPoints}.");
        }

        return new self(intdiv($this->amountMinor * $basisPoints, 10_000), $this->currency);
    }

    /**
     * Split this amount by basis-point weights that sum to 10_000.
     * Each share is rounded down; the remainder goes to the first share.
     *
     * @param  non-empty-list<int>  $basisPoints
     * @return non-empty-list<self>
     */
    public function allocateBps(array $basisPoints): array
    {
        if (array_sum($basisPoints) !== 10_000) {
            throw new InvalidArgumentException('Allocation basis points must sum to 10000.');
        }

        $shares = array_map($this->shareBps(...), $basisPoints);
        $allocated = array_sum(array_map(static fn (self $m): int => $m->amountMinor, $shares));

        $shares[0] = new self($shares[0]->amountMinor + ($this->amountMinor - $allocated), $this->currency);

        return $shares;
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amountMinor === $other->amountMinor;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor >= $other->amountMinor;
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount_minor' => $this->amountMinor, 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }
}
