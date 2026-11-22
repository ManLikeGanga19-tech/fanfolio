# Audit module

Immutable audit trail of privileged and money actions.

- **Owns tables:** `audit_log`
- **Publishes events:** (consumes only)
- **Public surface:** `App\Modules\Audit\Contracts\*` and `App\Modules\Audit\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
