# Creators module

Creator applications, KYC, profiles, pricing, payout methods.

- **Owns tables:** `creators`, `creator_kyc`, `payout_methods`
- **Publishes events:** `CreatorApplied`, `CreatorKycPassed`, `CreatorKycFailed`, `CreatorApproved`, `CreatorSuspended` (all after commit)
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
| POST | `/creator/kyc` | Opens a session with the KYC provider (D-11). A phone goes to its hosted page; a computer gets a QR code to continue on a phone (D-14, `Identity\Contracts\PhoneHandoff`) | 10 per hour per account (each start is a paid provider session) |
| GET | `/creator/kyc/return?ref=` | The provider sends the creator back here. The result is fetched from the provider for a check that belongs to this creator; nothing in the URL but the reference is read | 20 per minute per account |
| GET, POST | `/creator/payout` | Step 4 of 4: bank account, USDT (TRC-20) wallet or Paxum. Needs a passkey or authenticator code confirmed in the last 10 minutes (`step-up:second-factor`, SEC-04, D-13) and a passed ID check | 20 per minute per account |
| GET, POST | `/creator/review` | Review & submit (board 6). Needs the page, a passed ID check, a payout method and both statements, then goes to `/creator/status` | 20 per minute per account |
| GET | `/creator/status` | Board 7 "We're reviewing your account", board 8 "One thing to fix" when staff send it back, and the approved, ID expired, paused and rejected screens. Studio sends everyone it refuses here | none |

The routes act only on the signed-in account's own application; no ids appear in URLs.

## Staff review (decision D-10)

`/admin/creator-approvals` (Filament, admin panel) lists submitted applications: page, category, 18+ flag, document type and last 4, whether the selfie matched, the payout hint, whether the payout name matches the ID, and when it was submitted. No legal name, date of birth or full numbers (rule 13). The full approvals board replaces it in M8.

| Action | Needs | Result | Audit | Event / email |
|---|---|---|---|---|
| `ApproveCreator` | submitted, KYC `review`, a payout method | `active`, KYC `approved`, `approved_at` | `creator.approved` | `CreatorApproved` (Identity gives the creator role); "You're approved" |
| `RequestCreatorFix` | submitted | back to `onboarding` with `fix_kind` (`retake_id`, `payout`, `profile`) and an optional note under 300 characters, shown to the creator. `retake_id` sets KYC to `action_needed` | `creator.fix_requested` | "One thing to fix" |
| `RejectCreator` | submitted, a reason from `RejectCreator::REASONS` | KYC `rejected`, no retry. The creator sees a neutral message | `creator.rejected` with the reason | `CreatorKycFailed` for Trust & Safety; neutral email |
| `SuspendCreator` | active (idempotent) | `suspended`: publishing and earning stop at once | `creator.suspended` | `CreatorSuspended` (rule 9). The users board that calls it arrives in M8 |

Each Action checks, inside the Action, that the actor is staff with MFA (`Identity\Contracts\Accounts::isStaff`) and is not deciding on their own application, and locks the row. Submitting again clears the fix.

**ID viewer (D-01).** `GET /admin/kyc-documents/{creator}/{side}` (staff only, `staff` middleware) fetches the front, back or selfie frame from the provider through `IdentityVerifier::fetchDocumentImage` and streams it with `Cache-Control: no-store`. Nothing is kept. Each view is audited as `kyc.document_viewed`.

## Studio

`/studio` is a Filament panel (`App\Providers\Filament\StudioPanelProvider`). `Http\Middleware\EnsureStudioAccess` lets in only accounts with MFA for whom `CreatorEligibility::canPublish` is true. Anyone else (in review, sent back, rejected, ID expired, suspended) goes to `/creator/status`. It shares the admin panel's relaxed CSP (D-07); fan pages keep the strict one. For now it holds only a welcome page; posts, prices, earnings and payouts arrive with their milestones.

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

## Payout methods

One active method at a time. Saving a new one revokes the old one (`revoked_at`); rows are never deleted, so every payout can be traced to the method it used. A partial unique index keeps one active default per creator. Both the add and the revoke are audited (rule 12).

| Rail | Checked | Hint |
|---|---|---|
| `bank` | bank name (2–60 characters) and a 6–20 digit account number (spaces and dashes ignored) | `<bank> ····<last 4>` |
| `usdt` | TRC-20 address: `T` and 33 base58 characters | `USDT wallet ····<last 4>` |
| `paxum` | email address | `Paxum <first letter>····@<domain>` |

**Name match.** The name on the account is compared with the legal name from the last passed ID check, ignoring case, punctuation and word order. A mismatch is saved with `name_matches_kyc = false` and the creator is told our team will check it before the first payout; it is not refused. Payout rules that use the flag arrive with payouts in M3.

## Submitting

`SubmitApplication` moves `onboarding` to `submitted` and records `submitted_at` and `terms_accepted_at`, audited as `creator.submitted`. Submitting twice is harmless. It never grants publishing or earning: only staff approval does (next PR).

## Decisions

- D-08: initials instead of a profile photo until the M4 scanning pipeline.
- D-09: categories from the screens, in `fanfolio.creators.categories`.
- D-10: a minimal staff approval page in M2; the full board in M8.
- D-11: the ID and selfie capture runs on the provider's hosted page; expiry and renewal rules.

## Data protection

No ID images, selfies or full document numbers are stored (rule 10); only the provider reference, the result, the date, the document type, its last 4 characters and its expiry date. Tests check the `creators` and `creator_kyc` columns. The legal name and date of birth in `creator_kyc` are encrypted at the field level (rule 11); a test reads the raw row. In `payout_methods`, the rail details and the name on the account are encrypted too; screens, staff and audit entries see only `display_hint` (for example `Test Bank ····2222`). Tests read the raw rows.
