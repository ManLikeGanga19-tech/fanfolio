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
| GET | `/account` | Account hub | |
| POST | `/login/passkey/options`, `/login/passkey` | Log in with passkey (user verification required) | 20 per minute per address |
| GET, POST | `/login/mfa` | Second factor after a password: authenticator or recovery code | 5 per minute per account, 20 per address |
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

## Gateways

- `SmsSender`: `fake` (logs the message locally).
- `BreachedPasswordChecker` (SEC-03): `fake`, or `hibp` (Pwned Passwords range API; only a 5-character hash prefix leaves the server; fails open if the service is down).
- `IdentityVerifier`: `fake`.

## Data protection

- Phone numbers are encrypted (SEC-10); a keyed hash (`App\Support\Security\KeyedHash`) keeps them unique.
- Phone codes are stored only as keyed hashes.
- Logins, failed logins, logouts, sign-ups and phone verification write to Audit through `AuditLog`.

See `docs/backend-spec.md` for the module's rules.
