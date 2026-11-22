# Content module

Posts, media items, vaults, scheduling, PPV prices, version hash and publish gate columns.

- **Owns tables:** `posts`, `media_items`, `post_media`, `vault_folders`
- **Publishes events:** `PostPublished`, `MediaReady`
- **Public surface:** `App\Modules\Content\Contracts\*` and `App\Modules\Content\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
