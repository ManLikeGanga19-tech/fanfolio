# Collaborations module

Co-performer tagging, verification, per-post consent tied to version_hash, revenue splits, publish gate.

- **Owns tables:** `post_participants`, `participant_consents`, `collaborator_profiles`
- **Publishes events:** `ParticipantInvited`, `ParticipantVerified`, `ConsentSigned`, `ConsentWithdrawn`, `PublishGateOpened`
- **Public surface:** `App\Modules\Collaborations\Contracts\*` and `App\Modules\Collaborations\Events\*`. Everything else is private to this module.

Other modules must not read or write these tables or use this module's models; architecture tests enforce this. Domain events implement `ShouldDispatchAfterCommit`.

See `docs/backend-spec.md` for the module's rules.
