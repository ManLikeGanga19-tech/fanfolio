# Wallet module

Double-entry ledger, fan wallets, creator earnings, top-ups, holds, payouts, gateway webhooks.

- **Owns tables:** `ledger_accounts`, `ledger_transactions`, `ledger_entries`, `ledger_balances`, `topups`, `payouts`, `gateway_webhooks`
- **Publishes events:** `WalletToppedUp`, `PurchaseCompleted`, `PayoutSent`
- **Public surface:** `App\Modules\Wallet\Contracts\*` and `App\Modules\Wallet\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
