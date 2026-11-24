# Identity module

Sign-up, login, MFA, passkeys, devices, sessions, age verification.

- **Owns tables:** `users`, `user_devices`, `sessions`, `password_reset_tokens`, `email_verifications`, `phone_verifications` (unused since D-12), `webauthn_credentials`, `mfa_totp`, `mfa_recovery_codes`, `age_verifications`
- **Publishes events:** `UserRegistered`, `AgeVerified`, `DeviceRevoked`
- **Public surface:** `App\Modules\Identity\Contracts\*` and `App\Modules\Identity\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

## Routes (fan PWA)

| Method | Path | What | Limit (SEC-14) |
|---|---|---|---|
| GET | `/` | Landing (guests) | |
| GET, POST | `/signup` | Sign up step 1 | 10 per hour per address |
| POST | `/signup/passkey/options`, `/signup/passkey` | Sign up step 1 with a passkey instead of a password: the account is created only after the passkey checks out, then recovery codes are shown | 10 per hour per address (options), 20 per minute (passkey) |
| GET | `/signup/email` | Step 2 (D-12): enter the 6-digit code emailed at sign-up, or ask for one | |
| POST | `/signup/email/send` | Send or resend the code to the account's email (60 s between sends) | 5 per hour per account, 20 per address |
| POST | `/signup/email/verify` | Check the code (5 attempts, 10 minutes); sets `users.email_verified_at` | 10 per minute per account |
| GET, POST | `/login` | Email and password | 5 per minute per email and address, 30 per address |
| GET, POST | `/forgot-password` | Ask for a reset link; the same reply whether or not the email has an account | 5 per hour per email, 20 per address |
| GET, POST | `/reset-password/{token}`, `/reset-password` | Choose a new password (token hashed, single use, 60 minutes); signs out every device | as above |
| POST | `/logout` | Sign out | |
| GET, POST | `/signup/age` | Sign up step 3 (optional): 18+ check with ID or selfie age estimate | 5 starts per hour per account |
| GET | `/signup/age/return?ref=` | Back from the provider; the result is fetched from the provider, never read from the browser | |
| GET | `/account` | Account hub | |
| POST | `/login/passkey/options`, `/login/passkey` | Log in with passkey (user verification required) | 20 per minute per address |
| GET, POST | `/login/mfa` | Second factor after a password: authenticator or recovery code | 5 per minute per account, 20 per address |
| GET, DELETE, POST | `/login/devices` (+ `/{device}`, `/continue`) | Device limit reached at sign-in (D-03): sign out a device, then continue | |
| DELETE | `/account/devices/{device}` | Sign out a device (owner only, policy) | |
| POST | `/account/devices/sign-out-others` | Sign out of all other devices | |
| GET | `/account/security` | Devices & security: passkeys, authenticator app, recovery codes | |
| GET, POST | `/account/confirm` (+ `/passkey`) | Step-up "Confirm it's you" | as `/login/mfa` |
| POST, DELETE | `/account/passkeys…`, `/account/security/totp…`, `/account/security/recovery-codes` | Add or remove sign-in methods; need a step-up in the last 10 minutes | |

## Passwordless accounts and password reset

- "Continue with a passkey" makes an account with no password (`users.password` is null). It starts with MFA on and gets 10 recovery codes before the email step.
- A password log in for a passkey-only account gets the same answer and the same hashing work as a wrong password.
- "Forgot password?" (`ResetPassword`) is also how a passkey-only account adds a password. A reset never skips MFA: with a passkey or authenticator app, the next login still asks for it. Reset uses the sign-up password rules (`PasswordPolicy`), rotates the remember token, signs out every device and is audited.
- The reset email (`PasswordResetLink`) is queued and sent from Identity, like the sign-up email code (`EmailVerificationCode`); it can move to the Notifications module once that module exists.

## MFA and step-up (SEC-04)

Two levels of the `step-up` route middleware:

- `step-up`: any factor confirmed in the last 10 minutes. Signing in counts; so do a password (accounts without MFA) and a recovery code.
- `step-up:second-factor` (D-13, payouts and payout details): a passkey or authenticator code confirmed on "Confirm it's you" in the last 10 minutes. Signing in, a password or a recovery code does not count, and the confirm page hides them. An account without a passkey or authenticator app is sent to Devices & security to set one up. JSON requests get a 403 with `confirm` or `setup`.

Each step-up is audited as `identity.step_up` with `second_factor` true or false.

- A passkey (user verification required) or a confirmed authenticator app turns `mfa_enabled` on; removing the last one turns it off. Creators, moderators and admins cannot remove their last factor.
- With MFA on, a password alone never signs in: the account waits in `PendingLogin` (5 minutes) for a TOTP or recovery code. A passkey signs in directly.
- `EnsureMfaEnrolled` (web group) sends creators and staff without MFA to set it up.
- `step-up` route middleware (`EnsureRecentStepUp`) needs a factor confirmed in the last 10 minutes; signing in counts. Use it for payouts, payout-method changes (M3) and consent (M7).
- TOTP codes are single use (last accepted time step is stored). Recovery codes: 10, single use, keyed hashes, shown once.
- Passkey browser code: `resources/js/passkeys.js` (no inline script, CSP-safe). Tests use `Tests\Support\VirtualAuthenticator` for full ceremonies.

## Admin panel access (SEC-15, rule 13)

- `/admin` (Filament, `App\Providers\Filament\AdminPanelProvider`) has no login page: staff sign in on the main log in screen.
- `EnsureAdminAccess` admits moderators and admins only, with MFA on, from an address in `FANFOLIO_ADMIN_IP_ALLOWLIST` (IPs or CIDR; empty means closed outside local and testing). With `FANFOLIO_ADMIN_SSO_REQUIRED=true` the panel stays closed until an SSO driver exists (provider undecided).
- Every refusal writes `admin.access_denied` with a reason; each new admin session writes `admin.panel_opened`.
- Decision D-07: `/admin` and the Livewire endpoints it calls use a relaxed CSP (`unsafe-eval`, inline styles). Fan pages keep the strict CSP.
- Behind a load balancer or Cloudflare, trusted proxies must be configured so the allow-list sees the real client address (infrastructure, M9).
- **Staff abilities (SEC-15, plan M8a).** `Contracts\StaffAbility` lists what staff may do in `/admin`. Moderators have every ability except the admin-only ones (system health, suspending and reinstating creators, pausing payouts, bans and lifting them, second-factor resets, audit log, staff, settings; D-18). Each ability is a Gate of the same name; `User::canStaff()` also needs MFA on and an account allowed to sign in. Other modules ask `Gate::allows(StaffAbility::X->value)` in pages and `Accounts::can($userId, $ability)` inside Actions.

## Age verification

- `AgeVerifications::start()` opens a provider session (`IdentityVerifier`, purpose `fan_age`, method document or selfie age estimate) and records a pending row.
- `complete()` loads the row for the signed-in user only, asks the provider for the result and sets `users.age_verified_at` on a pass (`AgeVerified` after commit). Fail, under-18 and suspected fraud are recorded with a reason; review stays pending.
- `age_verifications` keeps the provider reference, method, status, reason and date only (rule 10): no images, document data or date of birth.
- "Not now, show safe content only" leaves the account unverified; adult content stays hidden.

## Devices (SEC-05, D-03)

- A device is a browser holding a random token in the encrypted, HttpOnly `ff_device` cookie; `user_devices` stores only its keyed hash. No browser fingerprinting.
- At most `fanfolio.identity.max_active_devices` (3) active devices. A new device past the limit is refused at sign-in, after every factor has passed; the user sees their devices and may sign one out, then continue (5-minute hold).
- `EnsureDeviceActive` (web group) ends a session on its next request once its device is signed out. Logging out frees the device slot.
- `last_city` stays empty until a location provider is chosen.
- `DeviceRevoked` is published after commit (playback sessions bound to the device stop in M4).

## For other modules

`Contracts\Accounts` answers what other modules may ask about an account by user id: `hasMfa`, `isStaff` (moderator or admin with MFA, allowed to sign in; for staff checks inside other modules' Actions), `can` (one staff ability, see above), `username`, and `notify`, which sends a notification to the account without giving the caller its email address or phone number (rule 13).

`Contracts\AccountStats` gives the admin Overview counts only: sign-ups in a range, how many of them passed the 18+ check, and sign-ups per calendar day in the viewer's time zone.

`Contracts\AccountDirectory` (search and `AccountSummary` for the Users & bans board) and `Contracts\AccountRestrictions` (suspend, ban, lift, sign out everywhere, `isRestricted`) serve Trust & Safety. `User::canSignIn()` refuses banned accounts and suspended ones until `suspended_until` passes; `EnsureDeviceActive` signs a restricted account out on its next request; `identity:end-suspensions` (every 5 minutes) sets ended suspensions back to active and audits `account.suspension_ended`.

## Time zone (D-15)

`users.timezone` holds the browser's IANA zone. `resources/js/timezone.js` compares it with `<meta name="ff-timezone">` (`<x-ui.timezone-meta />`, in every layout and both panels) and, when they differ, sends `POST /account/timezone` (signed in, 20 per hour, validated with `timezone:all`). `Http\Middleware\ApplyDisplayTimezone` (web group and both panels) sets Filament's display zone on every request, back to the app zone for guests, since Filament keeps it in a singleton under Octane. Stored times stay UTC; show them with `<x-ui.time :at="..." />` or Filament columns.

## ID and face scan on a phone (D-14)

`Contracts\PhoneHandoff` (used by the 18+ check here and by Creators' KYC):

- `returnUrl($userId, $next)`: a signed "All done" URL to give the provider for a session finished on a phone. The phone sees "Go back to your computer". The same browser, signed in as the same account, is sent on to `$next` with the provider's `ref`.
- `issue($userId, $providerUrl)`: a `HandoffLink` for the QR code. The token is random, stored as a SHA-256 hash in the cache, works once and lives 10 minutes. Issuing and opening are audited (`identity.phone_handoff_issued`, `identity.phone_handoff_opened`).

| Method | Path | What | Limit (SEC-14) |
|---|---|---|---|
| GET | `/verify/on-phone/{token}` | The phone opens the provider's capture (no sign-in; the link is the proof). Used or expired: 410 | 20 per minute per address |
| GET | `/verify/on-phone-done` | Signed; "All done, go back to your computer" | 20 per minute per address |

Forms that start a check send `device` (`phone` or `computer`), set by `resources/js/kyc-capture.js`. Without it, the server assumes a computer. The shared view is `<x-ui.phone-handoff>`, with an inline SVG QR code made on the server (`App\Support\View\QrCode`, chillerlan/php-qrcode).

## Development mode (D-14)

`FANFOLIO_DEV_MODE=true` (on in `.env.example` and `scripts/dev.ps1`) is honoured only with `APP_ENV=local`. `App\Support\DevMode::assertSafe()` stops the app booting in production with it on. When on:

- any 6-digit email code verifies (audited with `dev_mode: true`);
- the Fake KYC provider opens `/_fake/kyc/{ref}`, a stand-in capture page with an ID photo, a live camera selfie (or the camera app over plain http) and the result to return. The image fields have no `name`, so nothing leaves the device (rule 10);
- the QR page shows "Continue on this computer (development only)";
- `php artisan fanfolio:dev-staff <email> --role=admin|moderator` turns an existing account into staff (audited). Staff still need MFA to open `/admin`.

## Roles and panels

- `Listeners\GrantCreatorRole` gives an account the creator role when it hears `Creators\Events\CreatorApproved`. It is idempotent and audited as `identity.role_changed`.
- `User::canAccessPanel`: the `admin` panel is for staff, the `studio` panel for creators; both need MFA and an account that may sign in. Studio also checks creator eligibility (`Creators\Http\Middleware\EnsureStudioAccess`).
- Route middleware alias `staff` (`EnsureAdminAccess`) protects staff pages outside the Filament panel, such as the ID viewer.

## Gateways

- `SmsSender`: `fake` (logs the message locally). Not used by sign-up since D-12; kept for co-performer invites by phone (spec, Collaborations).
- `BreachedPasswordChecker` (SEC-03): `fake`, or `hibp` (Pwned Passwords range API; only a 5-character hash prefix leaves the server; fails open if the service is down).
- `IdentityVerifier`: `fake`.

## Data protection

- Sign-up email codes are stored only as keyed hashes (`App\Support\Security\KeyedHash`).
- Sign-up takes an optional phone number (D-12): `users.phone_e164`, encrypted (SEC-10), unverified. The verified email is the primary contact. `phone_hash` stays empty until a number is verified, so an unverified number cannot block its owner.
- Logins, failed logins, logouts, sign-ups and email verification write to Audit through `AuditLog`.

See `docs/backend-spec.md` for the module's rules.
