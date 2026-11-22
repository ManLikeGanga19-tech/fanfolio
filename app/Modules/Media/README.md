# Media module

Upload, scan, transcode, package, DRM keys, watermark and playback sessions.

- **Owns tables:** `media_assets`, `media_renditions`, `drm_keys`, `playback_sessions`
- **Publishes events:** `MediaScanned`, `MediaTranscoded`
- **Public surface:** `App\Modules\Media\Contracts\*` and `App\Modules\Media\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
