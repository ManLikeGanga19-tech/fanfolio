# TrustSafety module

Reports, moderation queue, strikes, bans, leak takedowns, hash matches.

- **Owns tables:** `reports`, `moderation_cases`, `strikes`, `takedowns`
- **Publishes events:** `ContentRemoved`, `UserBanned`
- **Public surface:** `App\Modules\TrustSafety\Contracts\*` and `App\Modules\TrustSafety\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.

## Users & bans (plan M8a, use cases 14–20 and 24)

`/admin/accounts` (Filament, `Filament\Admin\UsersBoard`, canvas board AdminUsers) lists accounts through `Identity\Contracts\AccountDirectory` (search by username, email or ID; filters All, Creators, Fans, Flagged, Suspended, Banned). Staff see an `AccountSummary` only: username, role, age verified, devices, status. Never the email, phone, legal name or date of birth (rule 13).

| Action | Who (D-18) | Result | Audit | Email (category only) |
|---|---|---|---|---|
| `SuspendAccount` | moderators 1 or 7 days, admins also 30 | `suspended` until the end date; every device signed out; lifts itself (`identity:end-suspensions`, every 5 minutes) | `account.suspended` | yes |
| `BanAccount` | admins | `banned`; every device signed out; a creator stops earning at once; `UserBanned` (Creators suspends the page) | `account.banned` | yes |
| `LiftRestriction` | admins | back to `active` | `account.restriction_lifted` | yes |
| `SignOutAccount` | moderators, admins | every device signed out; the account stays open | `account.signed_out_everywhere` | no |
| `Strikes::give` / `remove` | moderators, admins | a strike (table `strikes`); three active ones flag the account | `strike.given` / `strike.removed` | on give |

Every Action checks inside itself that the staff member holds the `Identity\Contracts\StaffAbility`, is not acting on their own account, and that the target is not staff. Every decision needs a reason category (`Contracts\RestrictionReason`) and a written note. The note goes to the audit log only; the user's email names the category. Emails go through `Identity\Contracts\Accounts::notify`.

`UsersBoard` also has **Flag for review** (`StaffAbility::FlagUser`): `FlagAccount` opens a "Flagged by staff" case in the moderation queue with the note (audited as `case.flagged`).

Not yet: blocking a banned person's verified ID from new accounts (D-17, needs the KYC result hash), age re-check and second-factor reset (later M8a PRs).

## Reports and the moderation queue (plan M8a PR 4, use cases 23 and 25–29)

**Reporting.** `POST /reports` (signed in, `throttle:reports`, 20 an hour) takes a creator page by handle (through `Creators\Contracts\CreatorPages`) or an account by username (`Identity\Contracts\AccountDirectory::findByUsername`), a reason (`Enums\ReportReason`: may be under 18, shared without consent, leaked or stolen, spam or scam, something else) and optional details (encrypted). The answer is the same whether or not the report counted. `Actions\FileReport` refuses reporting yourself and staff, joins reports about the same account into its pending "User report" case (keeping the most serious reason and counting reports), and ignores a repeat from the same person within a day. The sheet is `trustsafety::partials.report-sheet` (canvas FanMore · Report content), a native `<dialog>` opened by any `data-dialog-open` button (`resources/js/dialogs.js`); it is mounted on creator pages and profiles when they arrive in M5, and on posts and messages in M4 and M6.

**Minors first (use case 29).** A report or flag saying someone may be under 18 makes the case urgent: it goes to the top of the queue with a 1-hour target (`due_at`), and only an admin (`StaffAbility::DecideEscalatedCase`) can decide it. A moderator can escalate it.

**The board.** `/admin/moderation` (`Filament\Admin\ModerationBoard`, canvas AdminModeration, `StaffAbility::WorkModeration`) shows pending cases with tabs by source (`Enums\CaseSource`; hash match, AI flagged, consent withdrawn and leaks fill in with M4 and M7), urgent first then oldest. The detail pane shows the account or creator page, role, age verified, active strikes, other cases on the account, what reporters chose and wrote, but never who they are. The case number (`#1000` up) is for display only; URLs use the ULID.

| Action | Who | Result | Audit |
|---|---|---|---|
| `AssignCase::assign` / `release` | moderators, admins | holds the case; taking one over names the previous holder; only the holder releases | `case.assigned` / `case.unassigned` |
| `DecideCase` no action | moderators, admins (admins only for urgent or escalated cases) | closed | `case.decided` with the note |
| `DecideCase` warn | the same | closed; `AccountDecision::WARNING` email with the category | `case.decided` |
| `DecideCase` strike | the same | `Strikes::give` | `case.decided` + `strike.given` |
| `DecideCase` suspend | the same; days by role through `SuspendAccount` (D-18) | suspended | `case.decided` + `account.suspended` |
| `DecideCase` escalate | moderators, admins | `escalated`, waits for an admin | `case.escalated` |

A decision note is required and goes to the audit log only. Nobody decides a case about their own account. `Contracts\ModerationStats` gives the Overview its "Open cases" tile and the sidebar its count. Content decisions on the canvas (reveal a blurred preview, remove, remove + strike, escalate & report) arrive with posts in M4.
