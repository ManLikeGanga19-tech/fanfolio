# TrustSafety module

Reports, moderation queue, strikes, bans, leak takedowns, hash matches.

- **Owns tables:** `reports`, `moderation_cases`, `strikes`, `takedowns`
- **Publishes events:** `ContentRemoved`, `UserBanned`
- **Public surface:** `App\Modules\TrustSafety\Contracts\*` and `App\Modules\TrustSafety\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
