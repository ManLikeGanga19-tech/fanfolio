<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts;

use App\Modules\Wallet\Contracts\Data\PurchaseRequest;
use App\Modules\Wallet\Contracts\Data\PurchaseResult;
use App\Modules\Wallet\Contracts\Exceptions\LedgerException;
use App\Modules\Wallet\Contracts\Exceptions\PurchaseRefused;

/**
 * Spec "Spend flow (the hot path)": the one way other modules charge a fan's credits.
 * Call it inside your own database transaction and write the entitlement in that same
 * transaction, so a sale and its access commit together or not at all.
 */
interface Purchases
{
    /**
     * Posts fan_wallet (100%) → creator_pending (80%) + platform_revenue (20%), holds the
     * creator's share for 7 days, and dispatches PurchaseCompleted after commit. A repeated
     * idempotency key from the same fan returns the first result and charges nothing.
     *
     * @throws PurchaseRefused the creator cannot earn, the wallet is frozen, or the request is invalid
     * @throws LedgerException not enough credits, a busy wallet, or a reused key
     */
    public function charge(PurchaseRequest $request): PurchaseResult;
}
