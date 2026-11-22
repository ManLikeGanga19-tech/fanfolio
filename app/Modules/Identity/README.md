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
| GET | `/signup/phone` | Step 2: phone number, then code | |
| POST | `/signup/phone/send` | Send or resend a 6-digit code (60 s between sends) | 5 per hour per account, 20 per address |
| POST | `/signup/phone/verify` | Check the code (5 attempts, 10 minutes) | 10 per minute per account |
| GET, POST | `/login` | Email and password | 5 per minute per email and address, 30 per address |
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

## MFA and step-up (SEC-04)

- A passkey (user verification required) or a confirmed authenticator app turns `mfa_enabled` on; removing the last one turns it off. Creators, moderators and admins cannot remove their last factor.
- With MFA on, a password alone never signs in: the account waits in `PendingLogin` (5 minutes) for a TOTP or recovery code. A passkey signs in directly.
- `EnsureMfaEnrolled` (web group) sends creators and staff without MFA to set it up.
- `step-up` route middleware (`EnsureRecentStepUp`) needs a factor confirmed in the last 10 minutes; signing in counts. Use it for payouts, payout-method changes (M3) and consent (M7).
- TOTP codes are single use (last accepted time step is stored). Recovery codes: 10, single use, keyed hashes, shown once.
- Passkey browser code: `resources/js/passkeys.js` (no inline script, CSP-safe). Tests use `Tests\Support\VirtualAuthenticator` for full ceremonies.

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

## Gateways

- `SmsSender`: `fake` (logs the message locally).
- `BreachedPasswordChecker` (SEC-03): `fake`, or `hibp` (Pwned Passwords range API; only a 5-character hash prefix leaves the server; fails open if the service is down).
- `IdentityVerifier`: `fake`.

## Data protection

- Phone numbers are encrypted (SEC-10); a keyed hash (`App\Support\Security\KeyedHash`) keeps them unique.
- Phone codes are stored only as keyed hashes.
- Logins, failed logins, logouts, sign-ups and phone verification write to Audit through `AuditLog`.

See `docs/backend-spec.md` for the module's rules.
