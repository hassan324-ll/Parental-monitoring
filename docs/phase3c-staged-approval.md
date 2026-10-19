# Staged approval credential contract

This is a backward-compatible backend prerequisite for recoverable Android approval. Current Android still uses legacy server-generated approval tokens. It does not yet stage an approval credential; do not claim crash-safe pairing or change that client until the durable handoff is implemented/tested.

## HTTP behavior

`POST /api/v1/device-pairings/approve` still requires the one-use invitation code, affirmative `approved: true` and `device_name`; `android_version` is optional. Omitting `credential_hash` retains the existing server-generated credential response, including `device_token`.

A future client may additionally send `credential_hash`: exactly 64 lowercase hex characters, SHA-256 of the UTF-8 representation of a freshly SecureRandom-generated 64-hex-character (256-bit) credential. The client must durably encrypt and verify that credential before the affirmative approval request. The server receives/stores only its hash. In this mode HTTP 201 contains the device descriptor but MUST NOT return `device_token` or echo the hash. The raw credential, not its hash, authorizes companion requests.

Null, empty, padded, uppercase, malformed or non-string hashes fail validation. Client-supplied device/family/child ownership identifiers and plaintext `device_token` are prohibited. Family/child ownership comes exclusively from the invitation.

Successful approval locks and consumes the invitation once. An expired, absent or consumed invitation returns the same 404, including repeated identical staged requests. No approval replay/idempotency exception was introduced. Global credential history prevents another invitation, family or rotation from reusing a current or historical hash. Reuse returns a generic 422 and rolls back the provisional device and invitation consumption. Unique history reservation arbitrates concurrent claims. Existing parent authorization and device revocation remain unchanged; staging grants no monitoring permission.

## Future Android handoff requirements

1. Explicit invitation preview and affirmative consent remain mandatory. Prepare storage; reject an existing session; generate/encrypt/read back the approval credential before sending its hash.
2. Preserve the same staged credential across lost response/process restart. Do not blindly auto-approve or reuse a consumed invitation.
3. Restore only after authenticated companion status verifies the staged credential. A 401 after an uncertain request does not prove that an in-flight request cannot later commit; keep recovery state and provide parent-list review/revocation guidance.
4. Cancel before sending must never approve and must safely remove only unsent staging. After sending, reconcile or revoke; local erase does not revoke server access.
5. Distinguish pending approval from ordinary paired/rotation state durably, so a startup 401 cannot silently erase an unresolved transaction. Test missing key, failed stage/read-back, cancellation, lost response, process reconstruction, server revoke and cleanup before activation.

These client steps are requirements, not implemented features in this milestone. Even client protocol unit tests will not replace actual Keystore/process/UI/live API acceptance.

## Deployment boundary

No new migration is introduced; this service uses the existing credential history schema. That earlier migration remains applied only to isolated test databases, not development/production. No automatic rollout is performed. SQLite uses `:memory:`; PostgreSQL uses only `familyguard_test`. Actual Android/browser/inbox and CI acceptance remain open. Phase 4 collection remains gated by Phase 3 acceptance.
