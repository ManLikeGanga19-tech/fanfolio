# Identity module

Sign-up, login, MFA, passkeys, devices, sessions, age verification.

- **Owns tables:** `users`, `user_devices`, `sessions`, `password_reset_tokens`, `age_verifications`
- **Publishes events:** `UserRegistered`, `AgeVerified`, `DeviceRevoked`
- **Public surface:** `App\Modules\Identity\Contracts\*` and `App\Modules\Identity\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
