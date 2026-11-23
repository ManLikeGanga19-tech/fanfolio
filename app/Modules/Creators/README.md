# Creators module

Creator applications, KYC, profiles, pricing, payout methods.

- **Owns tables:** `creators`, `creator_kyc`, `payout_methods`
- **Publishes events:** `CreatorApplied`, `CreatorApproved`, `CreatorSuspended`
- **Public surface:** `App\Modules\Creators\Contracts\*` and `App\Modules\Creators\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules and `docs/plans/M2.md` for the build plan.

## Who can publish and earn (M2 "Done when")

`Contracts\CreatorEligibility::canPublish($userId)` and `canEarn($userId)` are true only for a creator who is approved by staff (`status = active`), has approved KYC, and is not suspended. Content must call it before publishing. Wallet and Subscriptions must call it before a sale or a payout. It reads the database every time, so a suspension stops publishing and sales at once (rule 9).

## Routes (fan PWA)

| Method | Path | What | Limit (SEC-14) |
|---|---|---|---|
| GET, POST | `/creator/start` | Become a creator. Starting needs MFA (SEC-04): without it the account is sent to Devices & security and comes back here | 20 per minute per account |
| GET, POST | `/creator/profile` | Step 1 of 4: display name, handle, category, adult flag. "Save & exit" returns to the account hub | 20 per minute per account |
| GET | `/creator/handle-check?handle=` | "Available" hint while typing; the save checks again | 60 per minute per account |

The routes act only on the signed-in account's own application; no ids appear in URLs.

## Handles

3–30 lowercase letters, digits, dots and underscores, starting and ending with a letter or digit, never two dots together. Unique regardless of case (`citext`). Reserved words are in `fanfolio.creators.reserved_handles`. The page address shows `[domain]/handle` until the domain is decided.

## Decisions

- D-08: initials instead of a profile photo until the M4 scanning pipeline.
- D-09: categories from the screens, in `fanfolio.creators.categories`.
- D-10: a minimal staff approval page in M2; the full board in M8.

## Data protection

No ID images, selfies or document numbers are stored (rule 10); a test checks the `creators` columns. KYC results (`creator_kyc`) and payout details (`payout_methods`) arrive in the next PRs, with field-level encryption (rule 11).
