# Creators module

Creator applications, KYC, profiles, pricing, payout methods.

- **Owns tables:** `creators`, `creator_kyc`, `payout_methods`
- **Publishes events:** `CreatorApplied`, `CreatorKycPassed`, `CreatorKycFailed`, `CreatorApproved`, `CreatorSuspended`
- **Public surface:** `App\Modules\Creators\Contracts\*` and `App\Modules\Creators\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules and `docs/plans/M2.md` for the build plan.

## Who can publish and earn (M2 "Done when")

`Contracts\CreatorEligibility::canPublish($userId)` and `canEarn($userId)` are true only for a creator who is approved by staff (`status = active`), has approved KYC, an ID that has not expired, and is not suspended. Content must call it before publishing. Wallet and Subscriptions must call it before a sale or a payout. It reads the database every time, so a suspension stops publishing and sales at once (rule 9).

## Routes (fan PWA)

| Method | Path | What | Limit (SEC-14) |
|---|---|---|---|
| GET, POST | `/creator/start` | Become a creator. Starting needs MFA (SEC-04): without it the account is sent to Devices & security and comes back here | 20 per minute per account |
| GET, POST | `/creator/profile` | Step 1 of 4: display name, handle, category, adult flag. "Save & exit" returns to the account hub | 20 per minute per account |
| GET | `/creator/handle-check?handle=` | "Available" hint while typing; the save checks again | 60 per minute per account |
| GET | `/creator/kyc` | Steps 2–3 of 4: ID document and selfie liveness, "Action needed" (board 8), or the result | none |
| POST | `/creator/kyc` | Opens a session with the KYC provider and sends the creator to its hosted page (D-11) | 10 per hour per account (each start is a paid provider session) |
| GET | `/creator/kyc/return?ref=` | The provider sends the creator back here. The result is fetched from the provider for a check that belongs to this creator; nothing in the URL but the reference is read | 20 per minute per account |

The routes act only on the signed-in account's own application; no ids appear in URLs.

## Handles

3–30 lowercase letters, digits, dots and underscores, starting and ending with a letter or digit, never two dots together. Unique regardless of case (`citext`). Reserved words are in `fanfolio.creators.reserved_handles`. The page address shows `[domain]/handle` until the domain is decided.

## ID and selfie check (KYC)

Each attempt is a `creator_kyc` row. `creators.kyc_status` follows the result:

| Provider result | `kyc_status` | What happens |
|---|---|---|
| Passed (18+, liveness passed, ID valid) | `review` | "ID and selfie matched"; staff approve after submit (D-10). `CreatorKycPassed` |
| Review (for example a name mismatch) | `review` | Staff decide |
| Under 18 (flag, reason or date of birth) or suspected fraud | `rejected` | No retry. Audited, `CreatorKycFailed` for Trust & Safety (rule 8) |
| Any other failure (blurry, liveness not finished, expired ID) | `action_needed` | "One thing to fix" with a retake |
| Pending | `pending` | "Check again" on the KYC page |

A reload of the return page, or a reference replaced by a newer check, changes nothing.

**ID expiry.** `creators.id_expires_at` holds the end of the expiry day of the approved ID. Once it passes, `CreatorEligibility` refuses publishing and earning. `creators:id-expiry-reminders` runs daily at 09:00 and emails each active creator once, 30 days before. In those 30 days the creator can verify a new ID; they stay approved when the provider passes it outright for the same person (name and date of birth). Otherwise the check goes to staff. A retake during renewal keeps the current ID in place.

The email goes through `Identity\Contracts\Accounts::notify`, so Creators never sees the email address.

## Decisions

- D-08: initials instead of a profile photo until the M4 scanning pipeline.
- D-09: categories from the screens, in `fanfolio.creators.categories`.
- D-10: a minimal staff approval page in M2; the full board in M8.
- D-11: the ID and selfie capture runs on the provider's hosted page; expiry and renewal rules.

## Data protection

No ID images, selfies or full document numbers are stored (rule 10); only the provider reference, the result, the date, the document type, its last 4 characters and its expiry date. Tests check the `creators` and `creator_kyc` columns. The legal name and date of birth in `creator_kyc` are encrypted at the field level (rule 11); a test reads the raw row. Payout details (`payout_methods`) arrive in the next PR.
