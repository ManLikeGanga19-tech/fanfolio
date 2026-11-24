# Identity module

Sign-up, login, MFA, passkeys, devices, sessions, age verification.

- **Owns tables:** `users`, `user_devices`, `sessions`, `password_reset_tokens`, `phone_verifications`, `webauthn_credentials`, `mfa_totp`, `mfa_recovery_codes`, `age_verifications`
- **Publishes events:** `UserRegistered`, `AgeVerified`, `DeviceRevoked`
- **Public surface:** `App\Modules\Identity\Contracts\*` and `App\Modules\Identity\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

## Routes (fan PWA)

| Method | Path | What | Limit (SEC-14) |
|---|---|---|---|
| GET | `/` | Landing (guests) | |
| GET, POST | `/signup` | Sign up step 1 | 10 per hour per address |
| POST | `/signup/passkey/options`, `/signup/passkey` | Sign up step 1 with a passkey instead of a password: the account is created only after the passkey checks out, then recovery codes are shown | 10 per hour per address (options), 20 per minute (passkey) |
| GET | `/signup/phone` | Step 2: phone number, then code | |
| POST | `/signup/phone/send` | Send or resend a 6-digit code (60 s between sends) | 5 per hour per account, 20 per address |
| POST | `/signup/phone/verify` | Check the code (5 attempts, 10 minutes) | 10 per minute per account |
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

- "Continue with a passkey" makes an account with no password (`users.password` is null). It starts with MFA on and gets 10 recovery codes before the phone step.
- A password log in for a passkey-only account gets the same answer and the same hashing work as a wrong password.
- "Forgot password?" (`ResetPassword`) is also how a passkey-only account adds a password. A reset never skips MFA: with a passkey or authenticator app, the next login still asks for it. Reset uses the sign-up password rules (`PasswordPolicy`), rotates the remember token, signs out every device and is audited.
- The reset email (`PasswordResetLink`) is queued and sent from Identity, like phone codes; it can move to the Notifications module once that module exists.

## MFA and step-up (SEC-04)

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

`Contracts\Accounts` answers what other modules may ask about an account by user id: `hasMfa`, `username`, and `notify`, which sends a notification to the account without giving the caller its email address or phone number (rule 13).

## Gateways

- `SmsSender`: `fake` (logs the message locally).
- `BreachedPasswordChecker` (SEC-03): `fake`, or `hibp` (Pwned Passwords range API; only a 5-character hash prefix leaves the server; fails open if the service is down).
- `IdentityVerifier`: `fake`.

## Data protection

- Phone numbers are encrypted (SEC-10); a keyed hash (`App\Support\Security\KeyedHash`) keeps them unique.
- Phone codes are stored only as keyed hashes.
- Logins, failed logins, logouts, sign-ups and phone verification write to Audit through `AuditLog`.

See `docs/backend-spec.md` for the module's rules.
