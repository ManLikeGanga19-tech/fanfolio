# Notifications module

Email, push, in-app and WebSocket fan-out.

- **Owns tables:** `notifications`, `push_subscriptions`
- **Publishes events:** (consumes only)
- **Public surface:** `App\Modules\Notifications\Contracts\*` and `App\Modules\Notifications\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
