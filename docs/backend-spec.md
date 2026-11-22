# Fan Platform – Backend Architecture Spec

Nov 21, 2026 · @Daniel Orwenjo

## Overview

Build one Laravel **modular monolith** with strict module boundaries, served as a web-only PWA, and scale it horizontally behind a CDN. Split a module into its own service only when load data proves it needs one.

**Scope:** a subscription platform for adult and safe-for-work creators in Africa. Fans buy wallet credits and spend them on subscriptions, pay-per-view (PPV) posts, tips and paid messages. Creators are verified, publish DRM-protected media and withdraw earnings by bank transfer, crypto or Paxum/Payoneer-style wallets.

**Guiding principles**

- **Money is never a column you update.** Every balance is derived from an append-only double-entry ledger.
- **Media never leaves the platform unencrypted.** Originals stay private; fans only receive DRM-encrypted, watermarked streams.
- **The database is not on the hot path.** Feed reads, entitlement checks and media delivery are served from Redis and the CDN.
- **Everything is idempotent and auditable.** Webhooks, top-ups and payouts can be retried safely, and every privileged action is logged.
- **Payment providers are pluggable.** A `PaymentGateway` interface lets card processors, crypto and later local rails be added without touching core logic.

**Companion boards:** [Enterprise security blueprint](https://claude.ai/artifact/CF3B4oKksmd2PRFY2kwCkW) and [Product map & user journeys](https://claude.ai/artifact/6BrJbEHGUdtfpVgboxyqQc).

&#91;embedded content: system architecture · clients, edge, app, data, external services\]

All traffic enters through the Cloudflare edge. Video segments come from R2 through the CDN, and access checks are answered by Redis, so Postgres only handles writes and cache misses.

## Tech stack and deployment

The stack is Laravel 12 on Octane, PostgreSQL, Redis and Cloudflare. All app servers are stateless containers, so capacity grows by adding instances.

| Layer | Choice | Why |
| --- | --- | --- |
| App framework | Laravel 12 + Octane (Swoole or FrankenPHP) | Keeps the app booted in memory; several times the throughput of PHP-FPM |
| Fan PWA | Livewire 3 + Blade + Tailwind, Shaka Player for DRM | Server-rendered and fast on low-end Android; Shaka handles Widevine, FairPlay, PlayReady |
| Creator studio + admin | Filament 4 panels (`/studio`, `/admin`) | Tables, forms and dashboards out of the box |
| Primary database | PostgreSQL 16, 1 primary + 2 read replicas | Transactions for the ledger; replicas for reads |
| Cache, sessions, locks, rate limits | Redis 7 cluster | Entitlement cache, feed cache, idempotency locks |
| Queues | Redis queues via Laravel Horizon (move to SQS or RabbitMQ if needed) | Transcoding, scans, notifications, payouts |
| Search | Meilisearch | Creator discovery |
| Object storage | Cloudflare R2, private buckets only | No egress fees on heavy video |
| CDN + WAF | Cloudflare (WAF, bot management, DDoS, signed URLs) | Edge delivery across Africa |
| Media processing | FFmpeg workers + Shaka Packager (CENC) | HLS/DASH, multi-bitrate, encrypted |
| DRM licences | DRM-as-a-service (e.g. EZDRM, Axinom, DoveRunner) | No licence server to run yourself |
| Real-time | Laravel Reverb (WebSockets) | Messages, notifications, live counters |
| Containers | Docker images; Kubernetes or ECS with autoscaling | Repeatable deploys, horizontal scaling |

**Environments:** `local` → `staging` (production-like, test payment keys) → `production`. Infrastructure is defined as code (Terraform) and every deploy goes through CI.

**Process types** (one image, different commands): `web` (Octane), `horizon` (queue workers, split by queue), `media-worker` (FFmpeg, CPU-heavy, own autoscaling group), `scheduler` (one instance) and `reverb` (WebSockets).

## Module boundaries

There are eleven modules (Collaborations has its own section below) under `app/Modules/<Name>`. Each owns its tables, and no module writes to another module's tables. Modules talk through a public service interface or domain events.

| Module | Responsibility | Owns tables | Publishes events |
| --- | --- | --- | --- |
| Identity | Sign-up, login, MFA, passkeys, devices, sessions, age verification | users, user\_devices, sessions, age\_verifications | UserRegistered, AgeVerified, DeviceRevoked |
| Creators | Creator applications, KYC, profiles, pricing, payout methods | creators, creator\_kyc, payout\_methods | CreatorApproved, CreatorSuspended |
| Content | Posts, media items, vaults, scheduling, PPV prices | posts, media\_items, post\_media, vault\_folders | PostPublished, MediaReady |
| Media | Upload, scan, transcode, package, DRM keys, watermark sessions | media\_assets, media\_renditions, drm\_keys, playback\_sessions | MediaScanned, MediaTranscoded |
| Wallet | Ledger, fan wallets, creator earnings, top-ups, holds, payouts | ledger\_accounts, ledger\_entries, ledger\_transactions, topups, payouts | WalletToppedUp, PurchaseCompleted, PayoutSent |
| Subscriptions | Plans, subscriptions, renewals, entitlements | subscription\_plans, subscriptions, entitlements | Subscribed, SubscriptionRenewed, SubscriptionExpired |
| Messaging | DMs, paid messages, mass DMs | conversations, messages, message\_unlocks | MessageSent |
| Trust & Safety | Reports, moderation queue, strikes, bans, leak takedowns, hash matches | reports, moderation\_cases, strikes, takedowns | ContentRemoved, UserBanned |
| Notifications | Email, push, in-app, WebSocket fan-out | notifications, push\_subscriptions | (consumes only) |
| Audit | Immutable audit trail of privileged and money actions | audit\_log (append-only) | (consumes only) |

**Rules for the terminal agent**

- Calls across modules only go through `Modules\<Name>\Contracts\*` interfaces, never through another module's Eloquent models.
- Domain events are dispatched **after commit** (`afterCommit`) and handled by queued listeners.
- Money moves only through `Wallet\Contracts\Ledger::post(Transaction)`. No other code writes balances.
- Enforce the boundaries with architecture tests (Pest `arch()`) so CI fails when a module reaches into another.

## Data model

All primary keys are ULIDs: sortable, and safe to expose in URLs. All money is stored as integer minor units with a currency code. Never use floats for money.

| Table | Key columns | Indexes and notes |
| --- | --- | --- |
| users | id, email (citext, unique), phone, password\_hash, role (fan/creator/admin/moderator), status, mfa\_enabled, age\_verified\_at, created\_at | unique(email); soft-delete only via a status flag |
| user\_devices | id, user\_id, device\_fingerprint, last\_ip, last\_seen\_at, revoked\_at | index(user\_id); max 3 active per fan |
| creators | id, user\_id, handle (unique), display\_name, category, is\_adult, kyc\_status, subscription\_price, status | unique(handle); index(status, category) |
| creator\_kyc | creator\_id, provider\_ref, id\_type, verified\_at, liveness\_passed, dob | encrypted columns; only the provider reference is stored, never ID images |
| subscription\_plans | id, creator\_id, period\_days (30/90/180), price\_minor, discount\_pct, active | index(creator\_id, active) |
| subscriptions | id, fan\_id, creator\_id, plan\_id, status, current\_period\_start, current\_period\_end, auto\_renew, cancelled\_at | unique(fan\_id, creator\_id) where status in (active, past\_due); index(current\_period\_end) for renewals |
| entitlements | fan\_id, scope\_type (creator/post/message), scope\_id, source (subscription/ppv/gift), expires\_at | PK(fan\_id, scope\_type, scope\_id); mirrored in Redis |
| posts | id, creator\_id, type, caption, visibility (free/subscribers/ppv), ppv\_price\_minor, is\_adult, status, published\_at | index(creator\_id, published\_at desc); partial index on status = published |
| media\_assets | id, owner\_id, kind (video/image/audio), status, original\_key, duration\_s, width, height, sha256, phash | unique(sha256); index(phash) for near-duplicate checks |
| media\_renditions | id, media\_id, profile (240p–1080p), manifest\_key, drm\_key\_id | index(media\_id) |
| playback\_sessions | id, fan\_id, media\_id, device\_id, watermark\_code, ip, started\_at | partitioned by month; used for leak tracing |
| ledger\_accounts | id, owner\_type, owner\_id, type (fan\_wallet/creator\_earnings/creator\_pending/platform\_revenue/processor\_clearing/payout\_clearing), currency | unique(owner\_type, owner\_id, type, currency) |
| ledger\_transactions | id, type, idempotency\_key (unique), reference\_type, reference\_id, created\_at | one row per business event |
| ledger\_entries | id, transaction\_id, account\_id, direction (debit/credit), amount\_minor | append-only; index(account\_id, id); partitioned by month |
| topups | id, fan\_id, gateway, gateway\_ref (unique), amount\_minor, credits, status | index(fan\_id, created\_at) |
| payouts | id, creator\_id, method\_id, amount\_minor, status, gateway\_ref, reviewed\_by | index(status, created\_at) |
| conversations / messages | conversation\_id, sender\_id, body (encrypted), media\_id, price\_minor | index(conversation\_id, id desc) |
| reports / moderation\_cases | id, target\_type, target\_id, reason, status, assigned\_to, decision | index(status, created\_at) |
| audit\_log | id, actor\_id, action, subject\_type, subject\_id, before, after, ip, created\_at | append-only; DB role cannot UPDATE or DELETE |

**Scale notes:** partition `ledger_entries`, `playback_sessions`, `messages` and `audit_log` by month from day one. Cache account balances in a `ledger_balances` table that is updated in the same transaction as each posting, and rebuild it from entries nightly to verify.

## Wallet and ledger

Every money event is one balanced ledger transaction: total debits equal total credits, or the transaction is rejected. Fans hold **credits**, shown in their local currency but stored in USD cents. Fans can spend credits but never withdraw them. Only creator earnings can be paid out.

**Postings per transaction type** (default platform fee 20%, configurable per creator)

| Transaction | Debit | Credit | Notes |
| --- | --- | --- | --- |
| Top-up confirmed | processor\_clearing | fan\_wallet | Only after a verified, signed gateway webhook |
| Subscribe / PPV / tip / paid message | fan\_wallet (100%) | creator\_pending (80%) + platform\_revenue (20%) | One DB transaction that also writes the entitlement |
| Hold released (after 7 days) | creator\_pending | creator\_earnings | Nightly job; skipped if a chargeback is open |
| Payout requested | creator\_earnings | payout\_clearing | Funds are locked while under review |
| Payout sent | payout\_clearing | processor\_clearing | On provider confirmation |
| Payout failed | payout\_clearing | creator\_earnings | Funds return automatically |
| Chargeback | creator\_pending (or creator\_earnings) + platform\_revenue | processor\_clearing | Fan wallet frozen; account flagged for review |
| Refund (admin) | creator\_pending + platform\_revenue | fan\_wallet | Requires an admin and an audit entry |

**Spend flow (the hot path)**

1. Client sends `POST /purchases` with an `Idempotency-Key` header.
2. Take a Redis lock on `wallet:{fan_id}` (2 s TTL) so the same fan's purchases run one at a time.
3. Open a DB transaction and `SELECT … FOR UPDATE` the fan's balance row. Reject if the balance is too low.
4. Insert the ledger transaction and its entries, update `ledger_balances`, and write the entitlement.
5. Commit, then write the entitlement to Redis and dispatch `PurchaseCompleted`.
6. If the same idempotency key arrives again, return the stored result without charging twice.

**Top-up flow:** fans choose a pack (e.g. $5 / $10 / $25 / $50). The gateway's hosted checkout collects the money, so no card data ever touches our servers. The gateway webhook is verified, stored raw, deduplicated on `gateway_ref` and processed on the `payments` queue.

**Payout flow:** a creator requests a withdrawal (minimum e.g. $20). It is checked against KYC, velocity limits and a SIM-swap/new-device rule, auto-approved below a threshold and reviewed manually above it. Approved payouts are sent through the payout driver (bank, crypto, Paxum).

**Integrity checks:** a nightly job verifies that every transaction balances, that cached balances equal the sum of entries, and that clearing accounts reconcile with gateway settlement reports. Any mismatch pages on-call.

## Subscriptions and entitlements

A subscription is paid from wallet credits for a fixed period and auto-renews from the wallet. No card is charged at renewal, so renewals cause no chargebacks or card declines. Access is checked through **entitlements**, never by querying subscriptions directly.

**Subscription state machine**

| From | Event | To | Side effects |
| --- | --- | --- | --- |
| (none) | Fan pays the plan price | active | Ledger spend, entitlement creator:{id} until period end |
| active | Renewal job runs and the wallet has enough credits | active | New period, ledger spend, entitlement extended |
| active | Renewal job runs and the wallet is short | past\_due | Notify fan: “top up within 3 days to keep access” |
| past\_due | Fan tops up (renewal retried automatically) | active | As a renewal |
| past\_due | 3-day grace ends | expired | Entitlement removed |
| active | Fan turns off auto-renew | cancelled | Access continues until period end, then expired |
| any | Creator suspended or banned | frozen | Entitlements paused; pro-rata refund policy applies |

**Renewals at scale:** the scheduler runs every minute and queues renewals in batches of 500 by `current_period_end`. Each renewal is its own idempotent job keyed `renew:{subscription_id}:{period_end}`, so retries never double-charge.

**Entitlement check (every feed item, every play)**

1. Look up Redis `ent:{fan_id}`, a hash of `creator:{id}` / `post:{id}` / `msg:{id}` mapped to expiry.
2. On a miss, load from the `entitlements` table, refill Redis (TTL 1 h) and answer.
3. Every purchase, renewal, expiry or refund writes the DB first, then updates the Redis key.

This keeps the check under about 1 ms and off the database for more than 99% of requests.

## Media and DRM pipeline

No upload is visible to anyone until it has been scanned, transcoded and encrypted. Fans only ever receive encrypted segments plus a short-lived licence tied to their session.

**Upload to publish**

1. **Direct upload.** The studio requests a pre-signed R2 multipart upload URL (valid 15 min) and uploads straight to a private `ingest` bucket, so files never pass through the app servers.
2. **Register.** On completion the app creates `media_assets` (status `uploaded`) and queues `ScanMedia`.
3. **Safety scan** (`scan` queue): compute SHA-256 and a perceptual hash, match against known child-abuse hash lists (e.g. PhotoDNA / industry hash sets), and run an AI classifier for minors and nudity. A match means the asset is quarantined, never processed, and an escalation case opens. An unclear result goes to human review.
4. **Transcode** (`media` queue, FFmpeg workers): an adaptive ladder from 240p to 1080p (H.264, AAC), thumbnails and blurred previews for teasers. Photos are converted to a one-frame video so they get DRM too.
5. **Package and encrypt:** Shaka Packager produces CMAF HLS + DASH with CENC (`cbcs`) encryption. Content keys come from the DRM provider's key server and only the key id is stored.
6. **Store:** encrypted renditions go to the private `media` bucket. Originals move to cold storage, encrypted with a KMS key.
7. **Ready:** `MediaReady` fires and the creator can publish.

**Playback**

1. The fan opens a post. The server checks the entitlement, creates a `playback_sessions` row with a unique `watermark_code`, and returns a manifest URL signed for 60 s and bound to the session.
2. Shaka Player fetches segments from the Cloudflare CDN. A Worker validates the signed token on every segment request.
3. The player requests a licence. Our licence proxy checks the session, entitlement and device limit, then asks the DRM provider for a licence (short duration, no persistence, HDCP required where supported).
4. **Visible watermark:** an overlay shows `@handle · watermark_code` moving across the player.
5. **Forensic watermark** (Phase 2): A/B segment variants are served per session, so a leaked copy reveals the `watermark_code`.
6. **Fallback:** a browser without hardware-backed DRM gets a capped resolution (480p) and a denser watermark.

**Screen-capture policy (web):** blur the player when the tab loses focus or visibility, and block right-click, download and drag. These are deterrents only; the guarantees come from DRM plus watermark tracing.

## Creator collaborations

A post that shows more than one person can publish only when every participant is ID-verified as 18+ and has signed consent for that exact version of the post. Revenue splits are paid by the ledger on every sale. A new **Collaborations** module owns this. It depends on Identity (KYC), Content (posts) and Wallet (splits).

**Rules**

- No one under 18 may appear in any content, adult or safe-for-work.
- Every participant passes the same ID and selfie check as creators. Participants without their own page get a collaborator-only account.
- Consent is per post and tied to a `version_hash` (SHA-256 of the media hashes, caption, price and visibility). Any edit to those creates a new hash, voids existing consents and unpublishes the post until everyone consents again.
- A participant can withdraw consent at any time. The post stops selling immediately, entitlements are suspended and a moderation case opens.

**Data model additions**

| Table | Key columns | Notes |
| --- | --- | --- |
| post\_participants | id, post\_id, user\_id (null until accepted), invite\_contact\_hash, role (owner/co\_performer), split\_bps, status, invited\_at, expires\_at | unique(post\_id, user\_id); index(status, expires\_at) |
| participant\_consents | id, participant\_id, version\_hash, signed\_at, signature\_method (passkey), device\_id, ip, withdrawn\_at, withdraw\_reason | Append-only; a new row for every re-consent |
| collaborator\_profiles | user\_id, kyc\_status, payout\_method\_id (optional) | For participants without a creator page |
| posts (new columns) | version\_hash, publish\_gate (open/blocked), participant\_count | Gate is recomputed on every participant event |

**Participant states**

| From | Event | To | Effect |
| --- | --- | --- | --- |
| invited | Opens invite and starts KYC | verifying | Invite token consumed |
| verifying | KYC approved, 18+ | pending\_consent | Can now view the post, watermarked to them |
| verifying | KYC fails or age under 18 | declined | Post blocked; under-18 or fraud opens an urgent Trust & Safety case |
| pending\_consent | Signs consent for current version\_hash | consented | Publish gate re-evaluated |
| pending\_consent | Declines | declined | Creator must remove them and re-edit |
| consented | Creator edits media, caption, price or visibility | pending\_consent | New version\_hash; post unpublished if live |
| consented | Withdraws consent | withdrawn | Post withdrawn from sale, moderation case opened |
| invited, verifying, pending\_consent | 7 days pass | expired | Creator notified |

**Publish gate:** a post may publish only if every participant is `consented`, every consent matches the post's current `version_hash`, and every participant's KYC is approved and unexpired. The check runs inside the publish transaction with `SELECT … FOR UPDATE` on the post. Scheduled posts re-check at publish time.

**Ledger postings for a collaborative sale** (example: a 250-credit PPV with a 30% co-performer split)

| Account | Debit | Credit |
| --- | --- | --- |
| fan\_wallet (fan) | 250 |  |
| platform\_revenue |  | 50 |
| creator\_pending (owner) |  | 140 |
| creator\_pending (co-performer) |  | 60 |

Splits come out of the owner's 80% and are stored in basis points. The split is snapshotted into each sale's transaction metadata, so later changes never alter past sales. Rounding remainders go to the owner. Chargebacks reverse every share proportionally. Withdrawing consent never claws back shares already earned.

**Endpoints**

| Method | Path | Purpose |
| --- | --- | --- |
| POST | /studio/posts/{id}/participants | Tag a verified creator or invite by phone or email, with split |
| DELETE | /studio/posts/{id}/participants/{pid} | Remove a participant and reopen editing |
| GET | /collab/invites/{token} | Invite summary; no media before KYC passes |
| POST | /collab/participants/{pid}/consent | Sign with passkey step-up; body carries version\_hash |
| POST | /collab/participants/{pid}/decline | Decline to appear |
| POST | /collab/participants/{pid}/withdraw | Withdraw consent with a reason |
| GET | /me/appearances | Posts the user appears in, with earned shares |

**Events:** ParticipantInvited, ParticipantVerified, ConsentSigned, ConsentWithdrawn, PublishGateOpened.

**Security requirements (added to the list below)**

| ID | Requirement | How it is verified |
| --- | --- | --- |
| SEC-19 | Invite tokens are single-use, expire after 7 days and are stored hashed. Post media is shown only to the invited user after KYC passes, watermarked to them | Feature tests |
| SEC-20 | Consent requires a WebAuthn step-up. Consent rows are append-only. A version\_hash mismatch blocks publishing | Feature and DB permission tests |
| SEC-21 | A KYC result showing under 18 or document fraud blocks the post and opens an urgent case. The inviting creator is told only that the person could not be verified | Feature tests |
| SEC-22 | The inviting creator never receives a participant's date of birth, ID number or ID images | API contract tests |

## API contracts

The PWA talks to a versioned JSON API under `/api/v1`, authenticated with Sanctum session cookies (same-site) plus CSRF. Filament panels use their own session-authenticated routes.

**Conventions**

- JSON:API-style resources; ULIDs as ids; money as `{ "amount_minor": 1000, "currency": "USD" }`.
- Cursor pagination only (`?cursor=…&limit=20`, max 50). No offset pagination.
- Every state-changing request that moves money requires an `Idempotency-Key` header. Results are stored for 24 h.
- Errors use RFC 9457 problem+json with a stable `code` (e.g. `insufficient_credits`, `not_entitled`).
- Rate limits per user and per IP in Redis. 429 responses carry `Retry-After`.

| Method | Path | Purpose | Auth |
| --- | --- | --- | --- |
| POST | /auth/register, /auth/login, /auth/otp/verify | Account and session | Public, rate-limited |
| POST | /auth/passkeys, /auth/mfa | Strong login | User |
| GET | /feed?cursor= | Home feed of subscribed creators | Fan |
| GET | /creators/{handle} | Public profile + teaser posts | Public |
| GET | /creators/{handle}/posts?cursor= | Posts (locked ones return a blurred preview only) | Fan |
| POST | /purchases | Subscribe, unlock PPV, tip, unlock message `{type, target_id, plan_id?}` | Fan + Idempotency-Key |
| GET | /wallet | Balance + recent transactions | Fan |
| POST | /wallet/topups | Start a top-up; returns the hosted checkout URL | Fan + Idempotency-Key |
| POST | /media/{id}/playback | Create playback session; returns signed manifest URL + licence URL | Fan (entitled) |
| POST | /drm/licence | Licence proxy (Widevine/FairPlay/PlayReady) | Playback session token |
| GET/POST | /conversations, /conversations/{id}/messages | DMs and paid messages | Fan / Creator |
| POST | /reports | Report a post, message or user | User |
| POST | /studio/uploads | Create a multipart upload; returns pre-signed URLs | Creator |
| POST | /studio/posts | Create or schedule a post | Creator (KYC approved) |
| POST | /studio/payouts | Request a withdrawal | Creator + MFA step-up |

**Inbound webhooks** (`/webhooks/{gateway}`): verify the signature and timestamp (reject if older than 5 min), store the raw payload, return 200 immediately and process on a queue. Deduplicate on the provider's event id.

**Outbound events:** WebSocket channels `private-user.{id}` for wallet, purchase and message events, and `private-creator.{id}` for live earnings and new subscribers.

## Scalability and performance

The design target is **50,000 concurrent viewers and 500 checkouts per minute at launch, with p95 API latency under 300 ms**, scaling 10x without a redesign. These are planning assumptions; adjust them once launch creators and their fan counts are known.

**Hot paths and how each stays fast**

| Path | Expected load | Design |
| --- | --- | --- |
| Video segments | Highest by far (several requests per second per viewer) | Served by the Cloudflare CDN from R2; token checked at the edge by a Worker; origin hit only on a cache miss |
| Entitlement check | Every feed item and every play | Redis hash per fan; DB only on a miss |
| Feed and creator pages | Most API reads | Fan-out on read from Redis sorted sets of recent post ids per creator; post cards cached 60 s; cursor pagination; served from read replicas |
| Purchases and top-ups | Spikes when a big creator posts or goes live | Per-fan lock + row lock (no global lock); webhooks queued; idempotency keys; per-user rate limits |
| Renewals | Peaks at the start of each month | Batched jobs spread across the day; per-subscription idempotency |
| Uploads and transcoding | CPU-heavy, bursty | Direct-to-R2 uploads; separate autoscaling media worker pool; queue priority for small files |
| Messages and live counters | Many small writes | Reverb WebSockets; counters in Redis, flushed to Postgres every 30 s |

**Scaling plan**

| Stage | Trigger | Action |
| --- | --- | --- |
| Launch | Up to \~50k concurrent viewers | 4–6 Octane pods, 1 Postgres primary + 2 replicas, 3-node Redis, autoscaled media workers |
| Growth (10x) | p95 > 300 ms or DB CPU > 60% sustained | Add pods and replicas; PgBouncer connection pooling; move hot counters fully to Redis |
| Large | Ledger or messaging write load dominates | Split Wallet and Messaging into their own services with their own databases (boundaries already in place) |

**Load-test gate:** before launch, a k6 test must sustain the targets for 30 minutes, including a simulated creator drop (5,000 purchases in 60 s), with no ledger imbalance and no double charges.

## Security requirements

Each requirement below is written so it can be tested. The terminal agent should add an automated test or CI check for every row it can.

| ID | Requirement | How it is verified |
| --- | --- | --- |
| SEC-01 | All traffic TLS 1.2+ (prefer 1.3); HSTS with preload; secure, HttpOnly, SameSite=Lax cookies | SSL Labs grade A+; header tests |
| SEC-02 | Strict CSP (no inline scripts except nonce'd), X-Frame-Options DENY, Referrer-Policy strict-origin | Header tests in CI |
| SEC-03 | Passwords hashed with Argon2id; breached-password check on sign-up and change | Unit tests |
| SEC-04 | MFA (TOTP or passkey) mandatory for creators, moderators and admins; step-up MFA for payouts and payout-method changes | Feature tests |
| SEC-05 | Max 3 active devices per fan; any session can be revoked remotely | Feature tests |
| SEC-06 | Authorisation through Laravel Policies on every resource; no ID-only access (IDOR) | Policy tests + automated IDOR checks in DAST |
| SEC-07 | Media URLs signed, valid at most 60 s, bound to session and device | Integration tests |
| SEC-08 | DRM licence issued only for an active entitlement + playback session; no persistent licences | Integration tests |
| SEC-09 | Every upload hash-matched and classified before processing; matches quarantined and escalated | Pipeline tests with known-safe test hashes |
| SEC-10 | PII and KYC fields, messages and DOB encrypted at rest (Laravel encrypted casts, keys in KMS) | Schema review + tests |
| SEC-11 | Secrets only in a secrets manager; none in the repo, env files or CI logs | Secret scanning (gitleaks) in CI |
| SEC-12 | Ledger tables append-only at the DB-role level; audit\_log cannot be updated or deleted | DB permission tests |
| SEC-13 | Webhooks verified by signature + timestamp; replays rejected | Unit tests |
| SEC-14 | Rate limits on login, OTP, purchases, uploads and messages | Feature tests |
| SEC-15 | Admin panel behind SSO + IP allow-list; least-privilege roles; every admin action audit-logged | Access review + tests |
| SEC-16 | Dependencies scanned; SAST (e.g. Larastan, Semgrep) and DAST (OWASP ZAP) on each release | CI gates |
| SEC-17 | Third-party pentest before public launch; findings fixed before go-live | Pentest report |
| SEC-18 | Data protection: consent records, data export and deletion on request, breach response runbook (72-hour notice) | Runbook + feature tests |

## Observability and operations

Every request, job and money movement can be traced end to end, and on-call is paged before users notice a problem.

- **Logs:** structured JSON with a `trace_id` on every request and job, shipped to a central store (e.g. Grafana Loki or OpenSearch). PII is redacted at the logger.
- **Metrics:** Prometheus + Grafana dashboards for p50/p95/p99 latency per route, error rate, queue depth and wait time per queue, Redis hit ratio, DB connections and replica lag, CDN cache hit ratio, and transcoding backlog.
- **Business metrics:** top-ups per minute, purchase success rate, renewal success rate, payout backlog, chargeback rate and ledger imbalance (must be 0).
- **Tracing:** OpenTelemetry across web, queue jobs and outbound gateway calls.
- **Errors:** Sentry for web and the PWA, with releases tagged.

| Alert | Threshold | Severity |
| --- | --- | --- |
| Ledger imbalance or reconciliation mismatch | Any | Page immediately |
| Purchase success rate | < 97% over 5 min | Page |
| p95 API latency | > 500 ms over 10 min | Page |
| Queue wait (payments, scan) | > 60 s | Page |
| Replica lag | > 10 s | Warn |
| Chargeback rate | > 0.75% monthly | Warn (processors act near 1%) |

**Backups and disaster recovery:** continuous Postgres WAL archiving with point-in-time recovery (RPO 15 min), daily encrypted snapshots copied to a second region, and a 4-hour RTO. A restore drill runs quarterly and is recorded. R2 buckets use object versioning and a cross-region copy for originals.

**Runbooks** (written before launch): payment gateway outage, DRM provider outage, leaked content incident, abuse-material escalation, data breach, and a creator payout dispute.

## Open decisions and build order

Three decisions are still open. None of them blocks the build: the build order below puts them behind interfaces.

- [ ] **Top-up processor:** adult-friendly card processor (CCBill or Segpay via a UK/EU entity), crypto gateway, or both. Code against `PaymentGateway` and start with a fake driver.
- [ ] **DRM provider:** EZDRM, Axinom or DoveRunner. Pick after the proof of concept in step 3.
- [ ] **Platform name and brand:** needed before the design system (design step 4).

**Build order for the terminal agent**

1. Repo, CI (tests, Larastan, Pest arch tests, gitleaks), Docker, staging environment.
2. Identity: registration, OTP, MFA, passkeys, devices, roles, audit log.
3. **DRM proof of concept:** upload → transcode → encrypt → play in Shaka on Safari, Edge and Chrome Android. This is the riskiest piece, so prove it early.
4. Wallet and ledger with a fake gateway, idempotency and nightly integrity checks. Wallet-module test coverage at 100%.
5. Creators + KYC, then Content and Media pipeline (scan, transcode, package), then Collaborations (participants, per-post consent, publish gate, ledger splits).
6. Subscriptions and entitlements with the Redis cache and renewal jobs.
7. Fan PWA screens and creator studio (after wireframes and the design system are signed off).
8. Messaging, notifications, Trust & Safety queues, admin panel.
9. Real payment and payout drivers, k6 load test, pentest, then private beta.
