<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

/** The result of Ledger::post. $replayed: the idempotency key was seen before and nothing was charged again. */
final readonly class PostedTransaction
{
    public function __construct(
        public string $id,
        public bool $replayed,
    ) {}
}
