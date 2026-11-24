# Wallet module

Double-entry ledger, fan wallets, creator earnings, top-ups, holds, payouts, gateway webhooks.

- **Owns tables:** `ledger_accounts`, `ledger_transactions`, `ledger_entries` (partitioned by month: `ledger_entries_default` and `ledger_entries_YYYY_MM`), `ledger_balances`, `topups`, `payouts`, `gateway_webhooks`
- **Publishes events:** `WalletToppedUp`, `PurchaseCompleted`, `PayoutSent`
- **Public surface:** `App\Modules\Wallet\Contracts\*` and `App\Modules\Wallet\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` ("Wallet and ledger") and the plan in `docs/plans/M3.md`.

## The ledger (M3 PR 1)

**One entry point.** `Contracts\Ledger::post(LedgerTransaction)` is the only way money moves (brief rule 1). `Actions\LedgerService` is the only class that writes ledger tables; `Tests\Unit\LedgerBoundaryTest` fails the build otherwise.

**Building a transaction.** `LedgerTransaction::make(type, idempotencyKey, postings, referenceType?, referenceId?, taxTag?)` takes `Posting::debit` / `Posting::credit` on an `AccountRef` (a person's account by their Identity account id, or a platform account) with a positive `Money` amount. It refuses fewer than two postings, mixed currencies, and debits that differ from credits (rule 2). `taxTag` labels tax lines; there is no tax maths until the owner confirms the rules. A 20% fee on fewer than 5 credits rounds down to zero, so callers leave the zero fee posting out (postings must be positive).

**Accounts** (`Contracts\Data\AccountType`, created on first use): `fan_wallet`, `creator_pending`, `creator_earnings` and `payout_clearing` belong to a person and may never go below zero; `platform_revenue`, `platform_promotions` (D-04) and `processor_clearing` belong to the platform. Only a transaction built with `allowingNegativeBalances()` may take a personal account below zero, for D-20 chargeback clawbacks. Balances read as credits minus debits. All amounts are integer cents (100 credits = $1).

**Posting** (rule 4). `post()` takes a Redis lock `wallet:{owner}` for each person debited (TTL `fanfolio.money.wallet_lock_ttl_seconds`, waiting up to `wallet_lock_wait_seconds`, else `LedgerException` `wallet_busy`). In one database transaction it then:

1. inserts the transaction under its unique idempotency key; a replay returns the first result with `replayed = true` and charges nothing, and the same key with different postings is refused (`idempotency_key_reused`);
2. locks the debited personal balance rows `FOR UPDATE` in account order and refuses anything that would go below zero (`insufficient_credits`);
3. writes the entries and moves the cached `ledger_balances`.

**Append-only** (SEC-12). Triggers refuse UPDATE, DELETE and TRUNCATE on `ledger_transactions` and `ledger_entries` for every database role. Mistakes are fixed with a reversing transaction.

**Partitions.** `wallet:ledger-partitions` (daily at 01:00) creates monthly partitions for this month and the next three; anything outside them lands in the default partition, so a posting never fails for want of one.

**Integrity** (spec "Integrity checks"). `wallet:verify-ledger` (nightly at 02:30, `Actions\LedgerIntegrity`) checks that every transaction balances with at least two entries and that every cached balance equals the sum of its entries. A mismatch is logged as critical; the result is kept under `VerifyLedger::RESULT_KEY` for the admin Overview. Reconciling clearing accounts with gateway settlements comes with top-ups and payouts.

**Tests.** Every Wallet feature test ends with `expectLedgerBalanced()` (in `Tests/Helpers.php`, available to other modules' money tests). CI runs `phpunit.wallet.xml` with Xdebug: 100% of lines in the module and 100% of branches on the money paths (`Actions/`), checked by `scripts/check-branch-coverage.php`. Xdebug counts some compiler paths no test can take (a loop that always runs once, enum constant fetches), so branches are gated where money moves rather than on data objects and fake drivers. In `Actions/`, import the global functions a class calls (`use function in_array;`): PHP 8.4 otherwise compiles such calls inside a namespace with a check for a namespaced function of the same name, a branch no test can take.

## Top-ups (M3 PR 2)

**Screens** (fan PWA, Blade, strict CSP): `/wallet` (canvas FanCore · Wallet & top-up), `/wallet/topups/{id}` (FanMore · Top-up success, with waiting and declined states), `/wallet/topups/{id}/receipt` and `/account/purchases/statement` (printable, D-22), `/account/purchases` (FanProfile · Purchases & receipts; top-ups until M5 and M6 add spending), `/account/currency` (D-21). The Account hub shows the wallet card, Purchases and Currency through `Contracts\WalletDisplay`. A fan sees only their own top-ups (404 otherwise).

**Packs** (`Support\TopUpPack`, from `fanfolio.topup.packs`, D-04): $5, $10, $25 (+4%), $50 (+10%); 100 credits = $1, the bonus rounded down.

**Flow** (spec "Top-up flow"):

1. `POST /wallet/topups` (throttle `topups`, 10 a minute) → `Actions\StartTopUp`: a pack and a method (`fanfolio.topup.methods`); refused for a frozen wallet (`wallet_freezes`, D-20) or, while `fanfolio.network_risk.refuse.topups` is on, a VPN or proxy (D-16). The form carries an idempotency key per page view (the `Idempotency-Key` header wins): a repeat returns the same top-up. A pending `topups` row is created and the fan is sent to the gateway's hosted checkout; no card data reaches us. Nothing is credited here.
2. `POST /webhooks/{gateway}` (no session, no CSRF, throttle `webhooks`) → `Actions\ReceiveGatewayWebhook`: the signature and timestamp are verified (SEC-13, 400 otherwise), the raw body is stored encrypted in `gateway_webhooks` once per event id, and `Jobs\ProcessGatewayWebhook` runs on the `payments` queue (Horizon `supervisor-payments`, long-wait alert at 60 s).
3. `Actions\ApplyGatewayEvent`: a confirmed payment whose amount matches posts `topup` (`processor_clearing → fan_wallet`, key `topup:{id}`) and, when there is one, `topup.bonus` (`platform_promotions → fan_wallet`, key `topup-bonus:{id}`), marks the top-up paid, audits `wallet.topped_up` and dispatches `WalletToppedUp`. A repeat or a second confirmation credits nothing. A wrong amount credits nothing and logs critical. A failed payment marks a pending top-up failed. Chargebacks and refunds are stored and wait for PR 6.

**Development:** with the fake gateway in local and testing, `/_fake/checkout/{ref}` stands in for the processor's page; Pay or Decline sends a real signed webhook through the same pipeline.

**Display currency** (D-21, `Actions\WalletDisplayService`): credits first, with "≈ $12.50" or "≈ KSh 1,625" beside them. The fan's choice is kept in `wallet_preferences`; without one, a Kenyan browser language (en-KE, sw) gets KSh. Rates come from `FxRateProvider`; when no rate is available (for example the fake driver refusing to run in production) the amount is left out. Display only: nothing is charged or stored in another currency.

## Spending and holds (M3 PR 3)

**`Contracts\Purchases::charge(PurchaseRequest)`** (`Actions\ChargePurchase`) is how other modules spend a fan's credits: Subscriptions (M5) for subscriptions and unlocks, Messaging (M6) for paid messages, tips. Call it inside your own database transaction and write the entitlement in that same transaction. It refuses, before money moves, a creator who cannot earn (`Creators\Contracts\CreatorEligibility::canEarn`, rule 9), a frozen wallet, paying yourself, a non-positive amount, a currency other than credits and a missing key (`Contracts\Exceptions\PurchaseRefused`, codes `creator_unavailable`, `wallet_frozen`, `invalid_purchase`); insufficient credits, a busy wallet and a reused key come from the ledger (`LedgerException`). It posts `sale.{kind}`: fan_wallet 100% → creator_pending 80% + platform_revenue 20% (`fanfolio.money.platform_fee_bps`, rounded down; the fee line is left out when it rounds to nothing, so a creator's share is never zero). The ledger key is `purchase:{fan}:{key}`, so a repeat returns the first result (`replayed`) and keys never collide between fans. It writes an `earning_holds` row (release in 7 days, `earnings_hold_days`), audits `wallet.purchase` and dispatches `PurchaseCompleted` after commit.

**Holds** (rule 3). `wallet:release-earnings` (daily at 03:00, `Actions\ReleaseHeldEarnings`) posts `hold.release` (creator_pending → creator_earnings) for each sale past its release time, keyed `release:{hold}`, skipping sales that are `frozen` (a dispute, PR 6) or refunded. Due rows are locked `FOR UPDATE SKIP LOCKED`, so two workers never take the same sale.

**Refunds** (spec "Refund (admin)", `Actions\RefundPurchase`). Admins only (`StaffAbility::RefundPurchase`), with a reason, audited as `wallet.refund`: creator_pending (or creator_earnings once released) and platform_revenue back to the fan's wallet, keyed `refund:{sale}`, once per sale. A creator who already withdrew the share goes below zero and repays it from future sales (D-20). Nobody refunds a sale they are part of. `PurchaseRefunded` tells the access owner (M5, M6) to revoke what it bought. The admin screen arrives with the money boards (PR 5).

**Gateways.** `TopUpGateway`, `PayoutGateways` (one driver per rail) and `FxRateProvider` sit behind interfaces with Fake drivers; tests never call a real processor.
