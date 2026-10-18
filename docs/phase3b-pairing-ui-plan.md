# Phase 3B — Visible, consent-based device pairing implementation plan

Status: design/implementation checklist only; no runtime verification claimed.

## Parent Angular workflow
- Add an authenticated child detail action: **Pair Android device**.
- Call the existing parent-scoped invitation endpoint and show the one-time code with its 10-minute expiration; never persist the code to local storage, analytics, or logs.
- Add device list, last connection state (only if backend provides it), and revoke action with confirmation.
- Show expiry, already-used, rate-limit, offline, and permission errors without exposing credential values.

## Android companion workflow
- Provide a visible app icon and an explicit pairing screen where the device user enters the invitation code.
- Preview the target family and child before requesting approval. Explain what pairing enables and what it does not enable.
- Require an affirmative user tap; cancellation must not call approve.
- Store returned device credential using Android Keystore-backed encrypted storage; do not log it, place it in backups, or expose it to other apps.
- Show persistent connected status and a **Disconnect** action that calls the self-disconnect endpoint and clears local credentials.
- Pairing must not grant screen capture, accessibility, notification access, location, or message access. Any later feature needs its own clear permission and visible controls.

## Acceptance tests to implement
1. Valid invitation preview, refusal, approval and parent device listing.
2. Expired/used code and repeated approval fail safely.
3. Family A cannot view or revoke Family B devices.
4. Android cancellation produces no pairing.
5. Disconnect and parent revoke invalidate subsequent device API calls.
6. App restart restores authorized session without exposing credentials.
7. Android and Angular loading, error, and accessibility states.
8. Verify pairing on an actual emulator/device with a real verified parent account.

## Release gates
- First run Phase 3A PHPUnit/migrations on isolated SQLite and PostgreSQL databases.
- Run Angular unit tests/build and Android debug build locally.
- Resolve GitHub Actions billing restriction and obtain CI evidence.
- Keep Phase 3A/3B draft and main unchanged until verification and review.
