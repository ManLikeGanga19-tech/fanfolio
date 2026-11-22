# Subscriptions module

Plans, subscriptions, renewals, entitlements (mirrored in Redis).

- **Owns tables:** `subscription_plans`, `subscriptions`, `entitlements`
- **Publishes events:** `Subscribed`, `SubscriptionRenewed`, `SubscriptionExpired`
- **Public surface:** `App\Modules\Subscriptions\Contracts\*` and `App\Modules\Subscriptions\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
