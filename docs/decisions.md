# Decisions record

Conflicts between sources, settled by the precedence rule in CLAUDE.md: the brief's locked decisions and non-negotiable rules first, then, per topic, the source listed first (backend spec → screens canvas → design system → security blueprint → product map).

| # | Conflict | Resolution | Authority |
|---|---|---|---|
| D-01 | Admin approvals board shows "ID document (front) · click to view" and a "Selfie frame"; creator intro screen says the ID is "stored encrypted" | We never store ID images or selfies. The admin viewer fetches the image from the KYC provider on demand (`IdentityVerifier::fetchDocumentImage`), streams it without caching or persisting it, and writes `kyc.document_viewed` to the audit log. Creator intro copy becomes: "Your ID is checked by our verification partner. We never store the image." | Brief rule 10; spec `creator_kyc` ("never ID images"); SEC-22 |
| D-02 | The creator "Review & submit" checkbox says "I will upload their ID and release forms before posting" | Co-performers verify themselves through the Collaborations flow (their own ID and liveness check plus a passkey-signed consent per post version). Checkbox copy: "Everyone in my content is 18+ and will verify their identity and consent to each post before it goes live." | Spec "Creator collaborations"; brief M7 and rule 8 |
| D-03 | Admin settings board: "Active sessions before the oldest is signed out: 3", while the brief requires a 4th device to be refused | A 4th device is refused at sign-in, and the user is shown their active devices so they can revoke one. The admin setting edits only the limit (default 3), not this behaviour. | Brief M1 "Done when"; spec `user_devices` "max 3 active"; SEC-05 |
| D-04 | The screens show top-up bonuses (+4% on $25, +10% on $50); the spec ledger table has no bonus posting | Bonus packs are config values (`fanfolio.topup.packs`), editable on the Fees & settings board with two-admin approval. The paid part posts `processor_clearing → fan_wallet` as in the spec. The bonus posts as a separate balanced transaction `platform_promotions → fan_wallet` (a new ledger account type), so revenue and clearing stay reconcilable against gateway settlements. | Screens (feature); brief rules 1–3 (ledger shape) |
| D-05 | Brief: PostgreSQL 17; spec: PostgreSQL 16 | PostgreSQL 17. The brief's stack is locked, and the spec does not govern the stack. | Brief "Stack (locked)" |
| D-06 | Spec build order puts a DRM proof of concept third; the brief puts Media/DRM at M4 | Follow the brief's milestone order (M0 → M9). | Brief "Build plan" governs build order |
| D-07 | SEC-02 requires a strict CSP (no inline script except nonce'd); Filament 4 (locked for Studio and Admin) runs on Livewire and Alpine, which build functions at runtime and need `unsafe-eval`. Livewire 3 has no CSP-safe mode | **Owner decision, 22 Nov 2026:** relax the CSP on `/admin` only (adds `unsafe-eval` and inline styles). `/admin` is behind mandatory MFA and an IP allow-list, with SSO behind a switch. The fan PWA keeps the strict CSP and stays server-rendered Blade with small bundled scripts (no Livewire or Alpine on fan pages). The same applies to the creator Studio panel when it is built. | Owner (security vs locked stack; brief "stop and ask") |
| D-08 | Creator onboarding board 2 has a "Profile photo" upload; rule 5 says no media is stored as public before malware and abuse-hash scanning, and that pipeline is M4 | Until M4, the creator page shows initials. The photo upload arrives with the M4 scanning pipeline | Brief rule 5 (non-negotiable) over the screens |
| D-09 | The spec gives creators a `category` but no list of categories | Use the categories shown on the screens (Music, Comedy, Fitness, Art, Lifestyle) as `fanfolio.creators.categories`, editable in config | Screens canvas (the spec is silent) |
| D-10 | Board 7 shows "Manual review: in progress" after the automatic KYC check, while the approvals board is in M8 | M2 ships a minimal staff page to approve, reject or ask for a fix (audited) so the flow can finish; M8 replaces it with the full board | Screens canvas for the flow; brief build plan for the board |

## Supporting interfaces needed by the screens but not listed under open decisions

Each gets an interface plus a Fake driver; no provider is chosen.

- `SmsSender`: phone one-time codes (sign-up step 2)
- `FxRateProvider`: "≈ KES" estimates next to credit amounts
- `MalwareScanner`, `AbuseHashMatcher`, `ContentClassifier`: upload scanning
- `LeakCrawler`: leak takedown board
- Admin SSO (SEC-15): behind a config switch; local admin login with mandatory MFA until a provider is chosen
