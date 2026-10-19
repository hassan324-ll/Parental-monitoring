# Phase 3C — Device credential rotation

## Implemented backend contract

`POST /api/v1/companion/rotate`, authenticated with the current device bearer credential, accepts `next_credential_hash`: lowercase SHA-256 hex of a replacement token. The replacement token is 32 cryptographically random bytes represented as 64 lowercase hex characters; hash the UTF-8 hex string, matching existing bearer authentication, not the raw random bytes. The server receives only the hash, never returns a replacement secret, and returns HTTP 200 with `rotated: true` plus public device id/name and `Cache-Control: no-store`. Shared transport shapes live in `packages/shared-contracts/device-rotation-*.schema.json`.

Authentication is rechecked inside a transaction after locking the device row. Old credentials fail immediately after commit; old status/disconnect requests authenticated earlier are rechecked and cannot mutate a newer session. Simultaneous rotations using one credential have one winner. Parent revocation takes the same lock and cannot be undone by rotation. The parent controller still checks family membership/role before passing the scoped device id to the service.

A unique hash-only history table prevents current and historical credential reuse across extant devices. New pairing records the initial hash; the migration backfills existing non-null hashes without changing active credentials. History rows cascade when a device is deleted; reuse prevention is bounded to that retained history, not an indefinite global tombstone. Hash history is never included in public device serialization. Deleting a family/device retains no history credentials.

Invalid request shapes receive 422; unauthenticated, stale or revoked bearer credentials receive 401; reused hashes receive a generic 422 without identifying another device/family. Nonempty device/family/child target identifiers are prohibited. Laravel trimming is disabled for the hash so malformed whitespace is rejected. Existing companion rate limits apply.

## Required Android handoff — not implemented in this backend slice

1. Generate a new credential with a platform CSPRNG. Atomically encrypt and read back a journal containing both current and pending credentials before sending the rotation request. Never overwrite the only usable credential first.
2. Send the pending credential hash with the current bearer token. Keep the journal through any timeout, cancellation, process restart or uncertain response. Do not generate another replacement while one is pending.
3. Verify authorization with the pending credential before atomically promoting it. On a lost response, probe pending/current state and retry the SAME staged hash if needed. Retrying the old bearer after commit can return 401; successful pending authentication confirms the committed replacement.
4. Both probes failing does not justify claiming rotation/disconnect success or erasing recovery state. Concurrent revocation, an in-flight commit, offline service and rejected credentials require distinct handling; fail closed, retain encrypted recovery material and show retry/parent-revocation guidance.
5. Disconnect during pending rotation must resolve/revoke the active generation, using both staged candidates when necessary. Never discard an unresolved session solely because its old token returns 401.
6. Add restart, persistence-failure, lost-response, double-submit, revocation and cancellation JVM tests, followed by actual Android Keystore/device acceptance. No Android rotation call or automatic schedule is enabled by this backend commit.

## Verification and deployment boundary

The isolated SQLite/PostgreSQL suites exercise replay, reuse, malformed input, cross-family/role authorization, stale service authorization, migration backfill/rollback, history privacy/cascade and actual two-process PostgreSQL races. The parent-revocation race calls the same service as the policy-protected HTTP controller; separate HTTP tests verify that policy boundary. SQLite skips process-lock race cases.

The new migration has run only on isolated test databases. Development/production databases were not migrated or reset. Apply reviewed migrations as part of a separately controlled environment rollout before using the new backend branch. Actual device, verified parent/inbox/browser and successful CI evidence remain pending; Phase 4 is not released by this change.