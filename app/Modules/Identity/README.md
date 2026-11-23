# Identity module

Sign-up, login, MFA, passkeys, devices, sessions, age verification.

- **Owns tables:** `users`, `user_devices`, `sessions`, `password_reset_tokens`, `phone_verifications`, `age_verifications`
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

## Gateways

- `SmsSender`: `fake` (logs the message locally).
- `BreachedPasswordChecker` (SEC-03): `fake`, or `hibp` (Pwned Passwords range API; only a 5-character hash prefix leaves the server; fails open if the service is down).
- `IdentityVerifier`: `fake`.

## Data protection

- Phone numbers are encrypted (SEC-10); a keyed hash (`App\Support\Security\KeyedHash`) keeps them unique.
- Phone codes are stored only as keyed hashes.
- Logins, failed logins, logouts, sign-ups and phone verification write to Audit through `AuditLog`.

See `docs/backend-spec.md` for the module's rules.
