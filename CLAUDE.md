FanFolio – Terminal Agent Handover Brief
Nov 22, 2026 · @Daniel Orwenjo
Mission and locked decisions
Build FanFolio: a web-only PWA where African creators sell subscriptions, pay-per-view posts, tips and paid messages to verified fans, with bank-grade money handling and content that cannot be casually stolen. All design work is done; your job is to implement it exactly, milestone by milestone.
Area
Decision (do not change without the owner)
Product
Fan PWA + creator studio + admin panel; adult and safe-for-work creators; launch niche starts with adult creators
Platform
Web only (PWA). No native Android or iOS apps
Architecture
Laravel modular monolith, 11 modules, stateless app tier, scale horizontally
Money
Fans buy credits into a wallet (100 credits = $1); credits are spent, never withdrawn by fans. Creators keep 80%; earnings held 7 days; payouts by bank, USDT or Paxum
Ledger
Append-only double-entry ledger is the only source of balances
Content protection
Multi-DRM (Widevine, FairPlay, PlayReady) + visible watermark per viewer + forensic watermark (phase 2) + 60 s signed URLs
Safety
No one under 18 in any content. Every person shown is ID + selfie verified and consents per post
Brand
Name FanFolio; dark theme default; amber brand for the single primary action and money
Sources of truth
When this brief and a source disagree, the source listed first for that topic wins; if two sources conflict, stop and ask. These live on claude.ai, so the owner exports them into the repo's /docs folder before you start.
Source
Governs
Put in repo as
Backend Architecture Spec
Modules, data model, ledger, subscriptions, media/DRM, collaborations, API, scaling, security requirements SEC-01 to SEC-22
docs/backend-spec.md (export as Markdown)
Screens canvas
Every screen: fan PWA, creator KYC, collaborations, studio, admin
docs/screens/ (export each board as PDF or PNG)
FanFolio design system
Colors, type, spacing, radius, voice, usage rules
docs/design-system/tokens.json + README.md
Security blueprint
Threat model overview and content-protection layers
docs/security-blueprint.png
Product map & journeys
Roles, journeys, sitemap, hot paths
docs/product-map.png
Figma file
Archive of the first screens and components; read with the Figma MCP if connected
Reference only
Wireframes
Superseded by the screens canvas
Do not build from these
Sample names, prices and numbers in screens are mock data. Build the layout and behavior, never the sample values.
Stack and repo conventions
Stack (locked): PHP 8.4, Laravel 12 on Octane (Swoole), Livewire 3 + Blade + Tailwind 4 for the fan PWA, Filament 4 for Studio and Admin panels, Shaka Player for playback. PostgreSQL 17 (one primary, two read replicas), Redis 7 (cache, locks, sessions, entitlements), Horizon queues, Meilisearch, Reverb for realtime. Media: Cloudflare R2 + CDN + WAF + Workers, FFmpeg and Shaka Packager (CENC cbcs), DRM through a multi-DRM service. Docker locally, Kubernetes (or ECS) in production, Terraform for all infrastructure.
Repo layout: a modular monolith. Each module lives in app/Modules/<Name>/ with its own Models, Actions, Events, Http, Policies, Jobs, Database/Migrations and Tests. The eleven modules are Identity, Creators, Content, Media, Wallet, Subscriptions, Messaging, TrustSafety, Notifications, Audit, Collaborations.
Boundaries: modules talk only through public Actions, contracts in app/Modules/<Name>/Contracts, and domain events. No module reads another module's tables. Enforce this with Pest architecture tests that fail the build.
Conventions:
• Primary keys are ULIDs. No auto-increment ids exposed in URLs or APIs.
• Money is always an integer of minor units (cents and credits) plus a currency code. No floats, no decimals in PHP.
• Domain events dispatch afterCommit. Listeners that touch money or entitlements are idempotent.
• Every write endpoint that moves money or grants access accepts an Idempotency-Key header.
• Static analysis: Larastan level max, Pint, Rector. Tests: Pest with parallel runs.
• Config through env and config/fanfolio.php; secrets never in the repo.
• UI uses the design-system tokens from docs/design-system/tokens.json mapped into Tailwind theme variables. No hard-coded hex values in views.
• Third-party gateways (payments, KYC, DRM, payouts, scanning) sit behind interfaces with a Fake driver used in local and test environments.
Build plan
Build in this order. Each milestone is one or more small PRs, ends with a short demo note to the owner, and is not done until every line in its "Done when" column passes in CI. Do not start a milestone before the previous one is merged, unless the owner says so.
M9 is the launch gate: nothing goes to real users until it passes.
Milestone
Scope
Done when
M0 Foundations
Repo per the conventions above, Docker setup, CI pipeline, Terraform skeleton, the 11 module folders, Pest architecture tests, Fake drivers for every gateway
CI is green; an arch test fails if one module reads another's tables; a new machine runs the app with one command
M1 Identity
Fan, creator and admin accounts; email plus passkeys; MFA; sessions; roles; device limit
Passkey and MFA flows pass; a 4th device is refused; admins cannot sign in without MFA
M2 Creator KYC
KYC screens from the canvas, ID and liveness through the KYC provider interface, 18+ check, creator profile, payout details
An unverified creator cannot publish or earn; we keep only the provider's reference and result, never ID images
M3 Wallet and ledger
Credits (100 = $1), top-up through the gateway interface, double-entry ledger, 7-day hold, payout requests, manual review above $1,000
Every test ends with the ledger balanced; a replayed idempotency key never charges twice; 100% test coverage on the Wallet module
M4 Media and DRM
Upload, malware and abuse-hash scanning, transcode, CENC cbcs packaging, DRM licences, 60-second signed URLs, visible and forensic watermarks
Nothing is playable before its scan passes; expired URLs fail; the watermark identifies the viewer
M5 Subscriptions
Plans, renewals, pay-per-view unlocks, tips, entitlements cached in Redis, 20% platform fee
Access checks read the cache; renewal and failure paths are tested; each sale posts the right ledger entries for fee and creator share
M6 Messaging
DMs, paid media in messages, mass messages, realtime through Reverb
Paid message unlocks use the same entitlement and ledger path as posts
M7 Collaboration
Tagging co-performers, their verification, per-post consent tied to version_hash, splits in basis points, publish gate, consent withdrawal
A post with any unverified or unconsented participant cannot publish; editing the post voids consent; withdrawal stops sales at once
M8 Admin and safety
Filament admin boards from the canvas: approvals, payouts, disputes, moderation, leak takedowns, users, audit, settings
Every admin action writes an audit entry; payouts follow the review rules in the spec
M9 Launch gate
Observability, k6 load tests, security review, external pen test, accessibility pass
k6 holds 50k concurrent viewers and 500 checkouts a minute at p95 under 300 ms; no open high-severity findings
Non-negotiable rules
These hold in every milestone. If a task seems to need breaking one, stop and ask the owner. Do not work around it.
Money
1.  Balances change only through ledger entries. No code updates a balance column directly; balances are derived or projected from the ledger.
2.  The ledger is append-only and double-entry. Every transaction's entries sum to zero. Mistakes are fixed with reversing entries, never edits or deletes.
3.  Money is integer minor units. Credits convert at 100 = $1. Platform fee is 20%. Creator funds are held 7 days. Payouts above $1,000 go to manual review.
4.  Every money-moving call takes an idempotency key and holds a Redis lock plus SELECT ... FOR UPDATE on the wallet row.
Content and access
5.  No media is stored as public or played before it passes malware and abuse-hash scanning.
6.  Paid media plays only through DRM with signed URLs that expire in 60 seconds, a visible watermark, and a forensic watermark tied to the viewer. Maximum 3 devices per account.
7.  Access is checked against entitlements on every playback and every message unlock. Never trust the client.
8.  No person under 18 appears in any content, ever. Every person in a post is a verified adult who has consented to that exact post version, or the post does not publish.
9.  Consent withdrawal or a failed verification stops sales of the affected post immediately.
Data and security
10.  We never store ID document images or selfies. Keep only the KYC provider's reference, result and date.
11.  Personal data (legal names, dates of birth, payout details, addresses) is encrypted at the field level.
12.  Every admin action and every change to money, verification or consent writes to the Audit module. Audit records are never edited.
13.  Admin access requires MFA. Staff see the minimum personal data needed for the task.
14.  Secrets live in the secrets manager, never in code, logs or tickets.
15.  Implement every requirement SEC-01 to SEC-22 in the backend spec. If one is unclear, ask rather than skip it.
Honesty in the product
16.  Do not claim in the UI that screen recording is blocked. On the web it cannot be fully blocked; the protection is DRM plus watermarks plus takedowns. Use wording such as "protected content".
Definition of done and CI gates
A PR merges only when every gate below passes. A milestone is done when its PRs are merged and its "Done when" column passes.
Gate
Rule
Blocks merge
Tests
Pest suite passes in parallel; every new Action and endpoint has feature tests
Yes
Wallet coverage
100% line and branch coverage on the Wallet and Subscriptions money paths
Yes
Architecture
Pest arch tests: no cross-module table access, no floats for money, no env() outside config
Yes
Static analysis
Larastan at max level, Pint, Rector dry run clean
Yes
Secrets
gitleaks finds nothing
Yes
Dependencies
Composer and npm audit: no known high or critical issues
Yes
SAST
Static security scan clean of high findings
Yes
DAST
Dynamic scan of staging before each milestone closes
Milestone
Load
k6 scenarios for feed, playback auth, checkout and messaging; must meet the M9 targets before launch
Milestone M9
Accessibility
Automated axe checks on every page; keyboard and focus-visible checks on the fan PWA
Yes
Migrations
Reversible, and safe for zero-downtime deploys (no locking rewrites of large tables)
Yes
Docs
Module README and API changes updated in /docs
Yes
Each PR description includes: what changed, which screens or spec sections it implements, how it was tested, and any rule from the section above it touches.
Open decisions and placeholders
These are not decided yet. Build behind an interface or a config value, use a Fake driver or the placeholder text exactly as written, and never invent a value.
Open item
Status
What to build now
Top-up processor
Undecided: CCBill or Segpay through a UK or EU entity, and/or crypto
TopUpGateway interface, a Fake driver, and webhook handling designed for signed, retried events
Payout rails
Bank, crypto and Paxum or Payoneer chosen; providers not contracted
PayoutGateway interface with a Fake driver per rail
DRM provider
Multi-DRM service not chosen
DrmLicenseService interface; local playback with clear-key in dev only
KYC provider
Not chosen
IdentityVerifier interface; Fake driver returns pass, fail and review results
Credit refund policy
Not written
Show [CREDIT REFUND POLICY] wherever the screens reference it
Privacy contact and domain
Not set
Use privacy@[domain] and [domain] placeholders
Legal entity and terms
Deferred by the owner
Placeholder pages for Terms, Privacy and 2257-style records notice, text marked [LEGAL TEXT]
Tax (KRA and others)
Rules not confirmed
Ledger tags for tax lines, no tax maths until the owner confirms the rules
Hosting region
Not confirmed
Keep region in Terraform variables
When one of these gets decided, the owner will update the spec. Only then replace the placeholder.
How to work and when to stop
Working loop for each milestone
1.  Read the matching spec sections and screen boards. Write a short plan in docs/plans/M<n>.md: tables, Actions, endpoints, screens, tests. Share it before writing code.
2.  Build in small PRs, each one reviewable in under 30 minutes. Tests first for anything that touches money, access or consent.
3.  Use Fake drivers for every outside service. Never call a real payment, KYC or DRM service from tests.
4.  Seed realistic but obviously fake data. No real names, faces or adult media in seeds; use placeholders.
5.  Close the milestone with a demo note: what works, what is stubbed, what is still open.
Stop and ask the owner when:
• A task would change a locked decision in the Mission section or a rule in Non-negotiable rules.
• The spec, the screens and this brief disagree.
• A security requirement conflicts with a feature or with performance.
• You need a value listed under Open decisions.
• A migration would lose data or needs downtime.
• You would add a new paid service, a new module or a major dependency.
Do not stop for: naming, small UI details already shown on the canvas, test structure, or refactors inside one module. Make a sensible choice and note it in the PR.
Authorship: you are a tool, not a collaborator. The owner is the only author of FanFolio. Never add yourself, your model or your tool anywhere in the project:
• No Co-Authored-By trailers, "Generated with" lines, signatures or links to your tool in commit messages, PR titles or PR descriptions.
• Commits use only the owner's git identity. Do not change git config user.name or user.email.
• Do not list yourself in composer.json or package.json authors, README credits, contributors files, CODEOWNERS, LICENSE or code comments.
• Do not add yourself, or any bot account, as a repository collaborator, reviewer or assignee.
• If your tool adds attribution by default, turn it off before the first commit. If you cannot, stop and tell the owner.
First task: start M0. Create the repo layout, CI and module skeleton, then send the M1 plan.