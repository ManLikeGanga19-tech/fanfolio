# Messaging module

DMs, paid messages, mass DMs.

- **Owns tables:** `conversations`, `messages`, `message_unlocks`
- **Publishes events:** `MessageSent`
- **Public surface:** `App\Modules\Messaging\Contracts\*` and `App\Modules\Messaging\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
