# Audit module

Immutable audit trail of privileged and money actions.

- **Owns tables:** `audit_log`
- **Publishes events:** (consumes only)
- **Public surface:** `App\Modules\Audit\Contracts\*` and `App\Modules\Audit\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

## Writing

Resolve `App\Modules\Audit\Contracts\AuditLog` and call `record(action, actorId, subjectType, subjectId, context)`. The request address and user agent are added. Context holds identifiers and outcomes only: never secrets, codes, passwords or document data.

Action names are `<module>.<event>`, for example `identity.login`.

## Append-only (rule 12, SEC-12)

A Postgres trigger rejects UPDATE, DELETE and TRUNCATE on `audit_log` for every role; the model refuses edits too.

## Testing other modules

Use `Tests\Support\RecordingAuditLog::swap()` to check what a module recorded without reading this module's table.

See `docs/backend-spec.md` for the module's rules.
