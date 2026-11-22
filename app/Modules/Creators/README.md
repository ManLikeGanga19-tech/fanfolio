# Creators module

Creator applications, KYC, profiles, pricing, payout methods.

- **Owns tables:** `creators`, `creator_kyc`, `payout_methods`
- **Publishes events:** `CreatorApproved`, `CreatorSuspended`
- **Public surface:** `App\Modules\Creators\Contracts\*` and `App\Modules\Creators\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
