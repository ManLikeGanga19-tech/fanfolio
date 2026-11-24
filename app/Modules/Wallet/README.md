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

**Tests.** Every Wallet feature test ends with `expectLedgerBalanced()` (in `Tests/Helpers.php`, available to other modules' money tests). CI runs `phpunit.wallet.xml` with Xdebug: 100% of lines in the module and 100% of branches on the money paths (`Actions/`), checked by `scripts/check-branch-coverage.php`. Xdebug counts some compiler paths no test can take (a loop that always runs once, enum constant fetches), so branches are gated where money moves rather than on data objects and fake drivers.

**Gateways.** `TopUpGateway`, `PayoutGateways` (one driver per rail) and `FxRateProvider` sit behind interfaces with Fake drivers; tests never call a real processor.
