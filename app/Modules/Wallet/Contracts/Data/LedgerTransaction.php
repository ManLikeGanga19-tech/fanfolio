<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use InvalidArgumentException;

/**
 * One business event as a balanced set of postings (spec: "Every money event is one
 * balanced ledger transaction"). Built and checked here; Ledger::post writes it.
 */
final readonly class LedgerTransaction
{
    /**
     * @param  non-empty-list<Posting>  $postings
     */
    private function __construct(
        public string $type,
        public string $idempotencyKey,
        public array $postings,
        public ?string $referenceType,
        public ?string $referenceId,
        public ?string $taxTag,
        public bool $allowNegative,
    ) {}

    /**
     * @param  list<Posting>  $postings
     */
    public static function make(
        string $type,
        string $idempotencyKey,
        array $postings,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $taxTag = null,
    ): self {
        if (preg_match('/^[a-z][a-z0-9_.]{1,39}$/', $type) !== 1) {
            throw new InvalidArgumentException("Invalid transaction type [{$type}].");
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191) {
            throw new InvalidArgumentException('An idempotency key of 1 to 191 characters is required.');
        }

        if (count($postings) < 2) {
            throw new InvalidArgumentException('A transaction needs at least two postings.');
        }

        $currency = $postings[0]->amount->currency;
        $sum = 0;
        foreach ($postings as $posting) {
            if ($posting->amount->currency !== $currency) {
                throw new InvalidArgumentException('All postings in a transaction use one currency.');
            }
            $sum += $posting->delta();
        }

        if ($sum !== 0) {
            throw new InvalidArgumentException('Debits and credits must be equal.');
        }

        return new self($type, $idempotencyKey, $postings, $referenceType, $referenceId, $taxTag, false);
    }

    /**
     * Lets personal accounts go below zero in this transaction. Only for the cases an
     * owner decision allows (D-20: a chargeback claws back a creator's share).
     */
    public function allowingNegativeBalances(): self
    {
        return new self($this->type, $this->idempotencyKey, $this->postings, $this->referenceType, $this->referenceId, $this->taxTag, true);
    }

    public function currency(): string
    {
        return $this->postings[0]->amount->currency;
    }

    /** What makes a replay the same request: the key must not be reused for anything else. */
    public function fingerprint(): string
    {
        $parts = [$this->type, (string) $this->referenceType, (string) $this->referenceId];
        foreach ($this->postings as $posting) {
            $parts[] = $posting->account->key().($posting->debit ? ':D:' : ':C:').$posting->amount->amountMinor.$posting->amount->currency;
        }

        return hash('sha256', implode('|', $parts));
    }
}
