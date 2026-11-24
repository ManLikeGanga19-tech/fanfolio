# M8a Admin and Trust & Safety foundations: plan

**Why now:** the owner asked (23 Nov 2026) for a full admin with many use cases, including moderation of creators and users, before M3 Wallet and ledger. This pulls forward the parts of M8 that do not depend on money, media or messaging. The boards that do depend on them (Payout reviews, Disputes, Leak takedowns, content cases) are built with their milestones, on the foundations laid here.

**Done when:** every admin action is allowed only to the right role (policy tests), writes an audit entry, and shows its result to the affected user; a banned or suspended account cannot sign in or earn; moderators and admins can work a queue from first report to decision without leaving `/admin`.

**Sources:** screens canvas pages 11–19 (Overview, Creator approvals, Moderation queue, Payout reviews, Disputes, Leak takedowns, Users & bans, Audit log, Fees & settings); spec module table (Trust & Safety owns reports, moderation_cases, strikes, takedowns; events ContentRemoved, UserBanned), data model (`users.status`), SEC-04, SEC-05, SEC-06, SEC-15, SEC-18; brief rules 9, 12, 13.

## Use cases

Built in M8a unless marked. "Mod" = moderator, "Admin" = admin. Every row writes an audit entry.

| # | Use case | Who | Board |
|---|---|---|---|
| **Overview** | | | |
| 1 | See today / 7 / 30 days: sign-ups, age-verified share, creators waiting (oldest), open cases (oldest), bans and suspensions | Mod, Admin | Overview |
| 2 | See system health: queue backlog and failed jobs, last scheduler run, provider (Fake) status | Admin | Overview |
| 3 | Sales, chargeback rate, payouts to review, ledger reconciliation tiles | Admin | Overview (**M3**) |
| **Creator approvals** (replaces the D-10 minimal page) | | | |
| 4 | Work the queue: list with check status (all checks passed, name mismatch, liveness failed, checks running), oldest first | Mod, Admin | Creator approvals |
| 5 | Assign to me / unassign; see who holds each application | Mod, Admin | Creator approvals |
| 6 | Detail pane: provider checks, age (DOB on reveal, logged), document type and last 4, payout name vs ID name, adult flag, submitted time | Mod, Admin | Creator approvals |
| 7 | View ID document / selfie frame from the provider on demand (D-01) | Mod, Admin | Creator approvals |
| 8 | Approve, request changes (ID, payout, page + note), reject (fixed reasons) | Mod, Admin | Creator approvals |
| **Creators** | | | |
| 9 | Directory of all creators by status (onboarding, submitted, active, suspended, rejected), search by handle or name | Mod, Admin | Creators |
| 10 | Creator detail: profile, KYC attempts (results only), payout methods (masked), status history from the audit log | Mod, Admin | Creators |
| 11 | Suspend (reason required) and reinstate; suspension stops publishing and earning at once | Admin; Mod can pause up to 7 days | Creators |
| 12 | Require a new ID check (e.g. document doubts): creator goes to "One thing to fix" | Mod, Admin | Creators |
| 13 | Pause payouts only (keep publishing) | Admin | Creators (**enforced from M3**) |
| **Users & bans** | | | |
| 14 | Search by username, email or ID; filters: all, creators, fans, staff, flagged, suspended, banned | Mod, Admin | Users |
| 15 | User detail: joined, role, age-verified, devices (n of 3), strikes, open cases, login history (from audit) | Mod, Admin | Users |
| 16 | Suspend for 1, 7 or 30 days (reason required); lifts itself when it ends | Mod, Admin | Users |
| 17 | Ban (reason required): revokes all sessions, blocks sign-in, stops a creator earning, dispatches UserBanned | Admin | Users |
| 18 | Lift a suspension or ban (reason required) | Admin | Users |
| 19 | Give a strike / remove a strike (reason required); three active strikes flag the account for a ban decision | Mod, Admin | Users |
| 20 | Sign out everywhere; revoke one device | Mod, Admin | Users |
| 21 | Ask the user to verify their age again; resend the email code | Mod, Admin | Users |
| 22 | Reset the user's second factor after an identity check, approved by a second admin | Admin + second Admin | Users |
| 23 | Flag an account (opens a moderation case) | Mod, Admin | Users |
| 24 | Email the user the outcome (suspended, banned, lifted, strike) by reason category, never the staff note | System | — |
| **Moderation queue** | | | |
| 25 | Queue of cases with tabs by source (user reports, flagged by staff; AI flagged, hash match, consent withdrawn, leaks arrive with M4/M7) and oldest-first | Mod, Admin | Moderation |
| 26 | Assign to me; case detail with the target, its history, related cases and the reporter count (reporters stay anonymous) | Mod, Admin | Moderation |
| 27 | Decide: no action, warn, strike, suspend, escalate to admin, with a decision note saved to the audit log | Mod, Admin | Moderation |
| 28 | Fans and creators report an account or creator page ("Report" sheet, canvas page 2) | Fan, Creator | fan PWA (**reports on posts M4, messages M6**) |
| 29 | Anything that may involve a minor goes to the top of the queue with a 1-hour target and an admin escalation | System | Moderation |
| **Audit log** | | | |
| 30 | Browse append-only entries: filters by actor, action, subject, date range; IP shown masked (e.g. 196.201.x.x) | Admin | Audit log |
| 31 | Export CSV of the current filter (the export itself is audited) | Admin | Audit log |
| **Staff** | | | |
| 32 | List staff with role, MFA method and last sign-in | Admin | Staff |
| 33 | Make an account a moderator or admin, or remove the role; the last admin cannot be removed; a new admin needs a second admin's approval | Admin | Staff |
| **Fees & settings** | | | |
| 34 | See the money and safety settings: platform fee 20%, 7-day hold, $1,000 manual review, 100 credits = $1, 3 devices. Read-only: these are locked in the brief | Admin | Settings |
| 35 | Propose and approve (second admin) changes to unlocked settings such as minimum withdrawal and top-up packs | Admin + second Admin | Settings (**M3**) |
| **Later milestones, on these foundations** | | | |
| 36 | Payout reviews (hold, approve & send, flags) | Admin | **M3** |
| 37 | Disputes and chargebacks (evidence, accept & refund, freeze wallet) | Admin | **M3** + top-up gateway |
| 38 | Content cases: reveal blurred media (logged), remove, remove + strike, escalate & report | Mod, Admin | **M4** |
| 39 | Leak takedowns (watermark trace, notice, ban leaker) | Admin | **M4** |
| 40 | Consent-withdrawn cases | Mod, Admin | **M7** |

## Roles (least privilege, SEC-15)

Two staff roles as in the spec. Laravel Policies decide every action; the table above is the matrix and gets a test per cell. Moderators see masked personal data only; DOB and ID images need an explicit, logged reveal (rule 13).

## Tables and contracts

- **Identity** (owns `users`): `status` (active, suspended, banned), `suspended_until`, `timezone` (D-15). Actions `SuspendAccount`, `BanAccount`, `LiftRestriction`, `SignOutEverywhere`, `RequestAgeReverification`, `ResetSecondFactor` (two-admin). Sign-in and every request refuse suspended or banned accounts. New contract methods on `Accounts`: `restrict`, `status`.
- **TrustSafety** (owns `reports`, `moderation_cases`, `strikes`, `bans`): Actions `FileReport`, `OpenCase`, `AssignCase`, `DecideCase`, `GiveStrike`, `RemoveStrike`. Bans call Identity through its contract and dispatch `UserBanned`; Creators listens and suspends the creator.
- **Creators**: `SuspendCreator` (exists) gets a UI, plus `ReinstateCreator`, `RequireIdRecheck`, approval assignment (`assigned_to`).
- **Audit**: read-only query contract for the board and CSV export.
- **Settings**: a `settings_proposals` table for two-admin approvals (staff roles now, money settings in M3).

All migrations are additive, nullable or defaulted, and reversible.

## Cross-cutting

- **D-15 Time zone (owner, 23 Nov 2026):** times are shown in the signed-in user's own time zone, read from the browser (`Intl.DateTimeFormat().resolvedOptions().timeZone`) and saved on the account. Stored times stay UTC. Staff see their own zone; the audit export is UTC with the zone named.
- **Network risk (VPN):** waiting for the owner's decision; see the open questions.
- **Accessibility:** axe and keyboard checks on every admin board (now possible with `fanfolio:dev-staff`). Row actions stay visible at laptop widths (fixes the cut-off buttons from the M2 walk-through).

## Proposed PRs

1. Admin shell and navigation from the canvas, role policies, Overview with real figures, D-15 time zone (built: branch `admin/01-shell-overview`)
2. Users & bans: account status, suspend, ban, lift, strikes, sign out everywhere, user detail, emails
3. Creator approvals board (canvas page 12) and Creators directory with suspend, reinstate and ID re-check
4. Moderation engine and queue: reports, cases, assignment, decisions; "Report" sheet for accounts
5. Audit log board with CSV export; Staff board with two-admin approvals
6. Settings board (read-only locked values) and network-risk rules if decided

## Tests

Policy test per role and action (including IDOR: a moderator cannot act on staff or on themselves); an audit entry per action; a banned or suspended account is refused at sign-in and on its next request; a suspension lifts itself; a ban stops a creator earning at once (`CreatorEligibility`); the last admin cannot be demoted; two-admin approvals cannot be approved by the proposer; times render in the user's zone; axe on every board.

## Owner decisions (23 Nov 2026, logged in docs/decisions.md)

- **D-15 Time zone:** as above.
- **D-16 VPN and proxies:** fans may browse and sign up behind a VPN; it is recorded as a risk signal on the account and shown in admin (Users: "Flagged · VPN"). A detected VPN or proxy is refused for staff sign-in, creator ID checks, payouts and payout-method changes, and top-ups ("Turn off your VPN or proxy to continue"). Each area is a switch in Fees & settings (two-admin change). Detection sits behind a `NetworkRiskCheck` interface with a Fake driver; the provider (an IP-reputation service) is not chosen, so it is a new open item. Top-ups and payouts get the check when M3 builds them.
- **D-17 Ban by verified ID:** a ban also stores a keyed one-way hash (HMAC with a key from the secrets manager) of the document number and date of birth from the provider result. It is used only to refuse a new KYC or age check from a banned person. Amends rule 10 for this one purpose; no ID number or image is stored.
- **D-18 Ban rights:** moderators suspend (up to 7 days) and give strikes; only admins ban and lift bans.

Use cases added by D-16: 41 see an account's network-risk signals (Users, Mod and Admin); 42 switch each VPN rule on or off (Settings, Admin + second Admin).
