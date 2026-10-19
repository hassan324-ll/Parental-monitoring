# Phase 3C — Android device session lifecycle

Authorized roadmap continuation: 19 October 2026. This milestone completes the remaining Phase 3 pairing prerequisites before Phase 4 usage/location work. It is not production sign-off.

## Implemented slice: credential persistence

- `DeviceCredentialStore` is an injectable suspend API for save/read/clear.
- `KeystoreDeviceCredentialStore` encrypts with a non-exportable Android Keystore AES-256 key and AES-GCM, uses a fresh provider-generated IV and authenticated version-specific context, and writes ciphertext atomically in `noBackupFilesDir`.
- Reads are bounded; invalid format, tampering, wrong/missing key and unavailable storage fail closed. There is no plaintext fallback. Operations run on IO and share a mutex. Clear removes ciphertext and key. Manifest already disables backup.
- This primitive is not wired into approval yet. No new permissions, monitoring, production endpoint or automatic consent are introduced.
- Four JVM tests exercise round-trip/randomized ciphertext, ciphertext and IV tampering, wrong key/version/truncation, and input/envelope size bounds. These use JVM cryptography, not Android Keystore. Three existing pairing-preview/cancellation tests remain passing.

## Next implementation slices

1. Add companion API status and disconnect calls using the stored bearer credential, never a Firebase parent token. No redirect forwarding or credential logging.
2. Add a session coordinator with injected API/storage and tests: restore only after server validation; clear on 401/revocation; preserve recoverable state on network failure; disconnect revokes server access and clears local credentials with honest offline/error status.
3. Connect lifecycle state to visible Android UI, then explicit preview/affirmative approval. Do not consume an invitation without storage readiness; handle persistence failure after approval without hiding an orphaned server session. Cancellation must never approve.
4. Implement/test credential rotation with race/replay handling and family isolation in isolated SQLite/PostgreSQL; document client/server recovery from interrupted rotation.
5. Run actual device/emulator acceptance for Keystore persistence, process restart, failed/missing key, clear/disconnect, parent revoke, visible consent and invitation cancellation, using a real verified parent account.

## Current evidence and gates

`gradlew.bat assembleDebug lintDebug testDebugUnitTest` passed after fixing a lint-confirmed API-33-only stream call to support minSdk 26. Seven JVM tests passed; lint: zero errors, 13 existing warnings. SDK command-line tooling also warned about XML version mismatch; build succeeded.

`adb devices` returned no device/emulator. Android Keystore file persistence, crash recovery, key invalidation and backup/device transfer behavior remain unexecuted platform acceptance, not proven by JVM crypto tests. Approval remains disabled until lifecycle integration is complete. Browser/inbox and CI gaps in the full report remain open. No main merge or production deployment is authorized by this document.

Reference: https://developer.android.com/privacy-and-security/keystore
## Implemented slice: restore, status and disconnect

- `CompanionSessionApi` and repository call existing `GET companion/me` and `POST companion/disconnect` contracts using a device bearer credential. Redirects and response caching are disabled, credentials are validated before header use, and JSON response size is bounded. No parent Firebase token is used.
- `DeviceSession` serializes operations. Startup restoration does not publish connected state until server validation. HTTP 401 clears revoked credentials; failed local cleanup is an explicit error. Network failures preserve retry credentials but never claim verified connectivity or successful server revocation. Cancellation leaves a retryable, unverified state.
- Twelve JVM tests cover no stored credential, server validation before connected state, credential-free observable state, 401 clearing, offline retry, storage failure, revoke-before-clear ordering, already-revoked cleanup, unconfirmed offline disconnect, cleanup retry, serialization and cancellation.
- `DeviceSessionViewModel` restores at startup; Connected Device Home and Connection Settings show status/retry controls and an affirmative disconnect confirmation. These are source/build-verified Compose changes; no runtime UI/device acceptance is claimed.
- Approval remains disabled pending storage-readiness checks, explicit consent integration and compensation for approval/persistence failure. No monitoring permissions or collection were added.