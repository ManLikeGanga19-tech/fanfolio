# Screens vs the canvas: conformance list

**Source:** the screens canvas, exported as board sources in `docs/screens/canvas/*.dc.html` (read from the owner's canvas on 24 Nov 2026; it replaces the PDF export for building). The owner's instruction: every screen resembles its board, including its icons and illustrations. Sample names, prices and numbers stay mock data and are never built in.

**How:** icons are the canvas's stroke icons (1.5 px, 24 px grid) as one Blade component, `<x-icon name="…">`. Spot illustrations are the canvas's 120 px drawings as `<x-illustration name="…">`, recoloured with design-system tokens (no hex in views). Owner decisions that changed a board stay as decided (D-08 photo, D-12 email code, D-13 optional phone, D-14 phone handoff) and are noted per row.

Status: **to do**, **done**, or **later** (the screen arrives with its milestone and is built to the board from the start).

## Built screens

| Screen | Board | Gaps found | Status |
|---|---|---|---|
| Log in (desktop) | AuthLogin | Carousel on the left (1.15 : 1) with logo mark "F" and left-to-right shade; passkey button with key icon; "Keep me logged in"; footer "FanFolio is for adults 18+. Protected by multi-factor sign-in."; 5 slides with their own headline and line | to do |
| Sign up (desktop) | AuthSignup | Same carousel; "I'm a fan / I'm a creator" switch (a creator continues to Become a creator after sign-up); "Free, and it takes under a minute."; "Sign up with a passkey" with key icon. Kept by owner decisions: optional phone (D-13), confirm password and show/hide | to do |
| Log in, sign up, email code, 18+ check (phone) | FanOnboarding | Back arrow; 3-segment progress bar; illustration on the code step (phone with message) and the 18+ step (shield "18+"); 6 code boxes. Step 2 stays email (D-12) | to do |
| Landing `/` (desktop) | MarketingHome | Full marketing page: header and nav, hero, trust row, how it works (3), earnings, ways to earn (6), safety (4), categories, FAQ, closing call to action, footer | to do |
| For creators `/creators` | MarketingCreators | New page: hero, 80% section, four steps, tools (6), payouts (3), protection (3), FAQ, closing call to action | to do |
| Landing (phone) | FanOnboarding · Landing | Featured creators row shows only once creator pages exist (M5) | later |
| Account hub | FanMore · Account | Avatar, name, username, "Age verified" pill; grouped rows. Wallet card, Subscriptions, Purchases, Notifications, Currency, Help arrive with their modules; bottom tab bar arrives with the feed (M4/M5) | to do (layout), later (rows) |
| Devices & security | FanMore · Security | Icon circles for passkey and authenticator; "On" pill; device rows with place and time; "Sign out of all other devices" | to do |
| Become a creator | Main · 1 | Icon circles (wallet, clock, lock) in the benefits card; check badges on "what you'll need"; back arrow. Copy per D-01 ("We never store the image") | to do |
| Set up your page | Main · 2 | 4-segment progress bar; camera upload circle (upload opens with M4 scanning, D-08); "Available" inside the handle field; chevron on category; 18+ as a switch | to do |
| Scan your ID / selfie | Main · 3–4 | Capture runs on the provider's page (D-11) and on a phone (D-14). Our start page gets the progress bar, icons for the tips and the shield note | to do |
| Payout method | Main · 5 | Progress bar; radio cards; check icon on "Name on account matches your ID" | to do |
| Review & submit | Main · 6 | Card rows; check-box style per board | to do |
| In review | Main · 7 | "FanFolio" + "Studio" pill header; hourglass illustration; timeline dots with check icons | to do |
| One thing to fix | Main · 8 | ID-card warning illustration; warning-bordered card with "Needed" pill; checklist of what is already fine; "Contact support" | to do |
| Studio shell | StudioDashboard | Studio sidebar and dashboard per board | later (with Studio features) |
| Admin: creator approvals | AdminApprovals | Sidebar with counts; list + detail pane with checks | to do (M8a PR 3) |
| Admin: other boards | Admin* | Built to the board in M8a and their milestones | later |

## Open items for the owner

- **Carousel:** the canvas has 5 slides; only slide 1 ("Create on your terms.") and slide 3 ("Closer to the creators you love.") are drawn, and the app has 3 photos. Needed: two more photos and the headline and line for slides 2, 4 and 5. Until then the carousel shows 3 slides.
