# Phase 3C — Android device session lifecycle

Authorized roadmap continuation: 19 October 2026. This milestone completes the remaining Phase 3 pairing prerequisites before Phase 4 usage/location work. It is not production sign-off.

## Implemented slice: credential persistence

- `DeviceCredentialStore` is an injectable suspend API for save/read/clear.
- `KeystoreDeviceCredentialStore` encrypts with a non-exportable Android Keystore AES-256 key and AES-GCM, uses a fresh provider-generated IV and authenticated version-specific context, and writes ciphertext atomically in `noBackupFilesDir`.
- Reads are bounded; invalid format, tampering, wrong/missing key and unavailable storage fail closed. There is no plaintext fallback. Operations run on IO and share a mutex. Clear removes ciphertext and key. Manifest already disables backup.
- This primitive is now wired into explicit approval, with readiness checks and read-back verification (see latest slice below). No new permissions, monitoring, production endpoint or automatic consent are introduced.
- Four JVM tests exercise round-trip/randomized ciphertext, ciphertext and IV tampering, wrong key/version/truncation, and input/envelope size bounds. These use JVM cryptography, not Android Keystore. Three existing pairing-preview/cancellation tests remain passing.

## Next implementation slices

1. Add companion API status and disconnect calls using the stored bearer credential, never a Firebase parent token. No redirect forwarding or credential logging.
2. Add a session coordinator with injected API/storage and tests: restore only after server validation; clear on 401/revocation; preserve recoverable state on network failure; disconnect revokes server access and clears local credentials with honest offline/error status.
3. Connect lifecycle state to visible Android UI, then explicit preview/affirmative approval. Do not consume an invitation without storage readiness; handle persistence failure after approval without hiding an orphaned server session. Cancellation must never approve.
4. Implement/test credential rotation with race/replay handling and family isolation in isolated SQLite/PostgreSQL; document client/server recovery from interrupted rotation.
5. Run actual device/emulator acceptance for Keystore persistence, process restart, failed/missing key, clear/disconnect, parent revoke, visible consent and invitation cancellation, using a real verified parent account.

## Current evidence and gates

`gradlew.bat assembleDebug lintDebug testDebugUnitTest` passed after fixing a lint-confirmed API-33-only stream call to support minSdk 26. Seven JVM tests passed; lint: zero errors, 13 existing warnings. SDK command-line tooling also warned about XML version mismatch; build succeeded.

`adb devices` returned no device/emulator. Android Keystore file persistence, crash recovery, key invalidation and backup/device transfer behavior remain unexecuted platform acceptance, not proven by JVM crypto tests. Explicit approval is now wired in development; actual platform acceptance remains pending. Browser/inbox and CI gaps in the full report remain open. No main merge or production deployment is authorized by this document.

Reference: https://developer.android.com/privacy-and-security/keystore
## Implemented slice: restore, status and disconnect

- `CompanionSessionApi` and repository call existing `GET companion/me` and `POST companion/disconnect` contracts using a device bearer credential. Redirects and response caching are disabled, credentials are validated before header use, and JSON response size is bounded. No parent Firebase token is used.
- `DeviceSession` serializes operations. Startup restoration does not publish connected state until server validation. HTTP 401 clears revoked credentials; failed local cleanup is an explicit error. Network failures preserve retry credentials but never claim verified connectivity or successful server revocation. Cancellation leaves a retryable, unverified state.
- Twelve JVM tests cover no stored credential, server validation before connected state, credential-free observable state, 401 clearing, offline retry, storage failure, revoke-before-clear ordering, already-revoked cleanup, unconfirmed offline disconnect, cleanup retry, serialization and cancellation.
- `DeviceSessionViewModel` restores at startup; Connected Device Home and Connection Settings show status/retry controls and an affirmative disconnect confirmation. These are source/build-verified Compose changes; no runtime UI/device acceptance is claimed.
- Explicit approval, readiness checks and compensation are now implemented as described below; actual device acceptance is still required. No monitoring permissions or collection were added.
## Implemented slice: explicit approval and partial-failure recovery

- Android approval requires a successful preview of the current code and an affirmative tap labelled **Approve pairing on this device**. Changing the code invalidates preview; cancellation before approval sends nothing. Duplicate submissions and editing/cancel actions after sending are blocked while completion/recovery runs.
- Storage readiness performs a separate encrypted atomic write/read probe without replacing an existing credential. Existing stored sessions cannot be overwritten by a new approval. The shared session mutex serializes approval, restore and disconnect.
- After the server issues a token, the app persists it and checks encrypted read-back before server validation. A save/read-back failure triggers compensating server disconnect and local cleanup. If compensation fails, recovery state keeps the credential in memory for explicit disconnect retry and instructs parent-side revocation before leaving/re-pairing. It is never exposed in UI state or diagnostic string output.
- Once an affirmative approval has started, coroutine cancellation does not discard the result before persistence or compensation finishes. This does not survive force-stop, process death or power loss. A dropped approval response can still consume a code without delivering the token; the UI explicitly reports uncertainty and directs parent device-list review/revocation. No exactly-once network transaction is claimed.
- Approval can finish as verified, securely saved but unverified/offline, failed, or recovery required. Only server-verified success claims a connected session. No monitoring permissions or data collection are enabled.
- Eleven approval transaction JVM tests plus four additional preview/consent tests bring the Android total to 34. Actual Android Keystore, UI consent, restart, live HTTP, backup exclusions and compensation on a device remain acceptance gates. Credential rotation and its server concurrency/replay protection remain the next implementation slice before Phase 4.
## Backend rotation milestone

Hash-only rotation and retained-history reuse prevention are now implemented with locked re-authentication and PostgreSQL race tests. Android durable staging/recovery and an explicit rotation confirmation are now implemented with JVM coverage; no automatic rotation is enabled and actual platform acceptance remains open. See [rotation contract](phase3c-credential-rotation.md).

## Android rotation handoff milestone

Versioned encrypted current/pending journals, legacy reads, staged-hash requests and recovery-aware restore/disconnect are implemented. Final Android suite: 57 passing JVM tests. These simulate restart/storage/network failures and do not establish real platform durability. See the rotation contract for conservative all-401 recovery limits and native acceptance prerequisites. SDK emulator executable is currently absent; emulator/instrumentation preparation is the next bounded task, not Phase 4 collection.
## Native test harness and prepared emulator

Eight isolated Keystore/AtomicFile instrumentation tests now compile, alongside 57 passing JVM tests. Emulator 37.2.12 and an API 26 AOSP x86_64 image are installed; FamilyGuard_API26_Test is prepared. Connected tests failed because no device is connected (zero executed). Windows acceleration remains disabled after a cancelled administrator prompt; no OS feature or reboot was performed. See [native acceptance setup](android-native-acceptance.md). This supersedes the earlier missing-emulator observation, without closing native acceptance or Phase 4 prerequisites.

## Explicit local recovery milestone

Connected Device Home and Connection Settings now offer a recovery-only local erase action for ERROR/OFFLINE states. The user must first acknowledge parent-side revocation and separately confirm loss of recovery data; the dialog states this is not verified by the app and local erase does not revoke server access. Cancel preserves recovery material. The coordinator rechecks eligibility under its mutex, sends no HTTP requests, clears and verifies storage, then drops compensation credentials. A cleanup failure blocks re-pairing and restoration in the current session until explicit retry succeeds. The failure latch is in memory; restart still reads actual storage and requires server validation. Connected sessions use Disconnect instead.

Nine new JVM recovery regressions pass, bringing the full Android suite to 66. App/test APK builds and lint pass (0 errors/13 warnings). Eight native instrumentation tests remain unexecuted. Real consent dialog, Keystore cleanup, process-death and live parent revocation acceptance remain open; local erase alone is never evidence of server revocation or Phase 4 readiness.

## Pairing transport boundary — 19 October 2026

Pairing preview/approval and companion status/disconnect/rotation now share a bounded UTF-8 response reader. It consumes at most 65,537 bytes, rejects bodies above 65,536 bytes before JSON parsing, strictly rejects malformed/truncated UTF-8, and closes streams on success or failure. Pairing requests disable URLConnection caching, matching companion requests. Errors introduced by the reader do not include response content. No HTTP contract, invitation replay behavior, credential format or approval consent changed.

The seven stream-reader JVM regressions verify exact byte boundary, early cutoff, multibyte/chunked responses, byte-vs-character limits, malformed encoding, stream failure/closure and zero-progress reads. These exercise actual JVM streams, not live Android HTTP or JSON parser integration.

Approval remains server-generated and one-use: the transaction consumes the invitation and stores only the credential hash, while the plaintext credential is returned once. A lost response/process death can therefore leave a server device that the app cannot restore. Do not retry automatically, recover via a replayed invitation, or claim local erase revokes that device. Review/revoke it in parent Connected Devices before creating a new invitation. A recoverable approval protocol would require deliberate client/server durable staging and authorization design plus isolated race tests; it is not implemented by this transport fix.

## Backend staged-approval prerequisite

The backend now accepts an optional client-staged `credential_hash` on explicit approval while preserving the legacy token response when omitted. Hash mode returns no plaintext token; invitation replay remains 404, expiry/consent stay mandatory, and globally reserved credential history prevents cross-device/family reuse. This is a backend prerequisite only: Android still uses legacy approval and does not stage an approval credential or recover it after process death. See [contract and remaining client work](phase3c-staged-approval.md). No production/development migration or feature activation is implied.
