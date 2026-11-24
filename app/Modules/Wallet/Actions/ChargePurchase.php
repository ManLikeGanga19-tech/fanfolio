<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Data\PurchaseRequest;
use App\Modules\Wallet\Contracts\Data\PurchaseResult;
use App\Modules\Wallet\Contracts\Exceptions\PurchaseRefused;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\Purchases;
use App\Modules\Wallet\Events\PurchaseCompleted;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function strlen;
use function strtolower;
use function trim;

/**
 * Spec "Spend flow (the hot path)" and postings "Subscribe / PPV / tip / paid message":
 * fan_wallet (100%) → creator_pending (80%) + platform_revenue (20%, rounded down; left
 * out when it rounds to nothing). The Ledger takes the Redis lock and the row lock on the
 * fan's wallet and refuses an overdraft (rule 4). The creator's share is held for 7 days
 * (rule 3); `ReleaseHeldEarnings` moves it to earnings.
 *
 * Refused before money moves: a creator who cannot earn (rule 9: suspended, unverified,
 * expired ID, restricted account), a frozen wallet (D-20), paying yourself, a non-positive
 * amount, a currency other than credits.
 */
final readonly class ChargePurchase implements Purchases
{
    public function __construct(
        private Ledger $ledger,
        private CreatorEligibility $eligibility,
        private AuditLog $audit,
    ) {}

    public function charge(PurchaseRequest $request): PurchaseResult
    {
        $key = trim($request->idempotencyKey);

        if ($key === '' || strlen($key) > 100) {
            throw PurchaseRefused::invalid('An idempotency key of 1 to 100 characters is required.');
        }

        if ($request->amount->amountMinor <= 0 || $request->amount->currency !== Money::CREDIT_CURRENCY) {
            throw PurchaseRefused::invalid('A purchase is a positive number of credits.');
        }

        if ($request->fanId === $request->creatorId) {
            throw PurchaseRefused::invalid('You cannot pay yourself.');
        }

        if (! $this->eligibility->canEarn($request->creatorId)) {
            throw PurchaseRefused::creatorUnavailable();
        }

        $fee = $request->amount->shareBps(config()->integer('fanfolio.money.platform_fee_bps'));
        $share = $request->amount->subtract($fee);

        return DB::transaction(function () use ($request, $key, $fee, $share): PurchaseResult {
            if (DB::table('wallet_freezes')->where('fan_id', $request->fanId)->whereNull('lifted_at')->exists()) {
                throw PurchaseRefused::walletFrozen();
            }

            $postings = [
                Posting::debit(AccountRef::of(AccountType::FanWallet, $request->fanId), $request->amount),
                Posting::credit(AccountRef::of(AccountType::CreatorPending, $request->creatorId), $share),
            ];
            if (! $fee->isZero()) {
                $postings[] = Posting::credit(AccountRef::platform(AccountType::PlatformRevenue), $fee);
            }

            // Keys are per fan, so two fans can never collide.
            $posted = $this->ledger->post(LedgerTransaction::make(
                'sale.'.$request->kind->value,
                'purchase:'.$request->fanId.':'.$key,
                $postings,
                $request->referenceType,
                $request->referenceId,
                'sale',
            ));

            if (! $posted->replayed) {
                DB::table('earning_holds')->insert([
                    'id' => strtolower((string) Str::ulid()),
                    'transaction_id' => $posted->id,
                    'fan_id' => $request->fanId,
                    'creator_id' => $request->creatorId,
                    'kind' => $request->kind->value,
                    'reference_type' => $request->referenceType,
                    'reference_id' => $request->referenceId,
                    'gross_minor' => $request->amount->amountMinor,
                    'creator_minor' => $share->amountMinor,
                    'fee_minor' => $fee->amountMinor,
                    'currency' => $request->amount->currency,
                    'release_at' => now()->addDays(config()->integer('fanfolio.money.earnings_hold_days')),
                    'created_at' => now(),
                ]);

                $this->audit->record('wallet.purchase', $request->fanId, 'ledger_transaction', $posted->id, [
                    'kind' => $request->kind->value,
                    'creator' => $request->creatorId,
                    'amount_minor' => $request->amount->amountMinor,
                    'fee_minor' => $fee->amountMinor,
                ]);

                PurchaseCompleted::dispatch($posted->id, $request->fanId, $request->creatorId, $request->kind->value, $request->amount->amountMinor, $request->referenceType, $request->referenceId);
            }

            return new PurchaseResult($posted->id, $posted->replayed, $share, $fee);
        });
    }
}
