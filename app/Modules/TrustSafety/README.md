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

Not yet: blocking a banned person's verified ID from new accounts (D-17, needs the KYC result hash), flagging an account into the moderation queue (PR 4), age re-check and second-factor reset (later M8a PRs).
