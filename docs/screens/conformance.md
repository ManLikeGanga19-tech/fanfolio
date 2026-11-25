# Screens vs the canvas: conformance list

**Source:** the screens canvas, exported as board sources in `docs/screens/canvas/*.dc.html` (read from the owner's canvas on 24 Nov 2026; it replaces the PDF export for building). The owner's instruction: every screen resembles its board, including its icons and illustrations. Sample names, prices and numbers stay mock data and are never built in.

**How:** icons are the canvas's stroke icons (1.5 px, 24 px grid) as one Blade component, `<x-ui.icon name="…">`. Spot illustrations are the canvas's 120 px drawings as `<x-ui.illustration name="…">`, recoloured with design-system tokens (no hex in views). Owner decisions that changed a board stay as decided (D-08 photo, D-12 email code, D-13 optional phone, D-14 phone handoff) and are noted per row.

Status: **to do**, **done**, or **later** (the screen arrives with its milestone and is built to the board from the start).

## Built screens

| Screen | Board | Gaps found | Status |
|---|---|---|---|
| Log in (desktop) | AuthLogin | Carousel on the left (1.15 : 1) with logo mark "F" and left-to-right shade; passkey button with key icon; "Keep me logged in"; footer "FanFolio is for adults 18+. Protected by multi-factor sign-in."; 5 slides with their own headline and line | done |
| Sign up (desktop) | AuthSignup | Same carousel; "I'm a fan / I'm a creator" switch (a creator continues to Become a creator after sign-up); "Free, and it takes under a minute."; "Sign up with a passkey" with key icon. Kept by owner decisions: optional phone (D-13), confirm password and show/hide | done |
| Log in, sign up, email code, 18+ check (phone) | FanOnboarding | Back arrow; 3-segment progress bar; illustration on the code step (phone with message) and the 18+ step (shield "18+"); 6 code boxes. Step 2 stays email (D-12) | done |
| Landing `/` (desktop) | MarketingHome | Full marketing page: header and nav, hero, trust row, how it works (3), earnings, ways to earn (6), safety (4), categories, FAQ, closing call to action, footer | done |
| For creators `/creators` | MarketingCreators | New page: hero, 80% section, four steps, tools (6), payouts (3), protection (3), FAQ, closing call to action | done |
| Landing (phone) | FanOnboarding · Landing | Featured creators row shows only once creator pages exist (M5) | later |
| Account hub | FanMore · Account | Avatar, name, username, "Age verified" pill; grouped rows. Wallet card (credits, ≈ amount, Top up), Purchases and Currency rows added in M3 PR 2; Subscriptions, Notifications, Privacy and Help arrive with their modules; bottom tab bar arrives with the feed (M4/M5) | done (layout, wallet, purchases, currency), later (other rows) |
| Wallet & top-up | FanCore · Wallet & top-up | Balance card with ≈ amount (D-21), four packs with bonus badges, Card / Crypto (USDT), "Continue to secure checkout", the partner note | done (M3 PR 2); bottom tab bar with the feed |
| Top-up success | FanMore · Top-up success | Credits added, new balance, amount, paid with, reference, "On your statement", Download receipt; plus waiting and declined states. "Back to the post" reads "Back to your wallet" until posts exist (M4) | done (M3 PR 2) |
| Purchases & receipts | FanProfile · Purchases & receipts | Tabs, month header, rows with +/− credits, statement, "Get help". Top-ups now; subscriptions, unlocks and tips with M5/M6. The header says "added" for top-up months (the board's "spent" applies once spending exists). "Download statement (PDF)" is a printable page (D-22) | done (M3 PR 2, top-ups) |
| Devices & security | FanMore · Security | Icon circles for passkey and authenticator; "On" pill; device rows with place and time; "Sign out of all other devices" | done |
| Become a creator | Main · 1 | Icon circles (wallet, clock, lock) in the benefits card; check badges on "what you'll need"; back arrow. Copy per D-01 ("We never store the image") | done |
| Set up your page | Main · 2 | 4-segment progress bar; camera upload circle (upload opens with M4 scanning, D-08); "Available" inside the handle field; chevron on category; 18+ as a switch | done |
| Scan your ID / selfie | Main · 3–4 | Capture runs on the provider's page (D-11) and on a phone (D-14). Our start page gets the progress bar, icons for the tips and the shield note | done |
| Payout method | Main · 5 | Progress bar; radio cards; check icon on "Name on account matches your ID" | done |
| Review & submit | Main · 6 | Card rows; check-box style per board | done |
| In review | Main · 7 | "FanFolio" + "Studio" pill header; hourglass illustration; timeline dots with check icons | done |
| One thing to fix | Main · 8 | ID-card warning illustration; warning-bordered card with "Needed" pill; checklist of what is already fine; "Contact support" | done |
| Studio shell | StudioDashboard | Studio sidebar and dashboard per board | later (with Studio features) |
| Admin shell and Overview | AdminOverview | Wordmark with red "Admin" label; text sidebar with counts; Today / 7 / 30 days; KPI cards; bar chart; System health with coloured dots. Admins see Sales (GMV), Payouts to review and the sales chart in the canvas order, plus the ledger check; moderators see sign-ups instead (use case 3 is admin-only). Chargeback rate waits for M3 PR 6; other sidebar items join as their boards are built | done (M8a PR 1, money tiles M3 PR 5) |
| Admin: payout reviews | AdminPayouts | Four tiles (waiting, auto-paid today, failed 7d, payout clearing reconciled); queue table with Creator, Amount, Method, Flags pills, Requested; detail pane with ✓ checks, Approve & send, Hold, explainer line | done (M3 PR 5; adds Decline and a note box for the audit log, creators shown by @username; the chargeback line waits for PR 6) |
| Admin: Users & bans | AdminUsers | Search, filter pills, table with status pills, detail pane with required reason and Ban / Suspend / Sign out everywhere. Spend, disputes and leak rows wait for M3 and M4; the note about blocking the verified ID waits for D-17 | done (M8a PR 2) |
| Studio · Earnings & withdrawals | StudioEarnings | Available (brand border), Pending with next release, Paid out all time; Withdraw with amount, Max, methods, note; history with status pills | done (M3 PR 4); "Paid to collaborators" with M7; Studio's sidebar and dashboard per StudioDashboard come later |
| Admin: creators directory | none (follows AdminUsers) | Filters, search, detail pane with ID checks and payout methods masked | done (M8a PR 3b) |
| Admin: creator approvals | AdminApprovals | Sidebar with counts; list + detail pane with checks | done (M8a PR 3; search box added for long queues, payout name shown as match only (rule 13), face-match score and sanctions lines wait for the KYC provider) |
| Admin: moderation queue | AdminModeration | Tabs by source with counts, case list with priority dot, detail with signals, decision note and buttons | done (M8a PR 4; account cases only: the blurred preview, Reveal and Remove buttons come with posts in M4; "Assign to me" sits in the detail pane next to who holds the case) |
| Report sheet | FanMore · Report content | Anonymous note, five reasons with "under 18" in red, optional details, Submit report | built (M8a PR 4); shown on creator pages and profiles when they arrive in M5 |
| Admin: other boards | Admin* | Built to the board in M8a and their milestones | later |

## Open items for the owner

- **Marketing photos:** the four category tiles on Home (Music, Fitness, Fashion and beauty, After dark) have no images yet; the board's mock figures (studio balances, prices) are left out on purpose.

- **Keep me logged in** (AuthLogin): left out until a remember token keeps the 3-device limit and remote sign-out (SEC-05). Planned as its own PR.
- **Support inbox:** "Contact support" uses `support@[domain]` (`FANFOLIO_SUPPORT_EMAIL`) until the domain is set.

- **Carousel:** 5 slides with the owner's photos (24 Nov 2026). Slides 4 ("Create together, with consent.") and 5 ("Your content, protected.") reuse the landing page's safety-card wording, pending owner approval. Transitions use Motion (motion.dev, the plain-JavaScript version of Framer Motion); with reduced motion on, nothing animates or auto-plays.
- **Home · Explore:** larger bento tiles with photos (owner, 24 Nov 2026) instead of the canvas's 220 px placeholders; tiles rise in on scroll with Motion.
