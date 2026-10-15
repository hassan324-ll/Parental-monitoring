# Phase 3A — Secure device pairing backend (draft)

This change is a **backend foundation**, not a finished device-pairing feature. It continues the new project paths: `backend/`, `frontend/` and `apps/child-android/`.

Implemented:
- A parent-authenticated, family-scoped endpoint creates a cryptographically random 128-bit invitation code for an existing child. Only its SHA-256 hash is stored; code expires in 10 minutes and is single-use.
- A rate-limited companion preview provides the target family/child name so the phone user can decide whether to pair.
- A companion approval endpoint requires an explicit `approved=true` value, consumes the invitation in a transaction, and issues a unique 256-bit device credential. The database stores only its hash.
- A separate device-auth middleware protects minimal companion status and self-disconnect endpoints, independent of parent Firebase authentication.
- Authorized parents can list and revoke family devices. Revocation clears the credential hash immediately. The model hides credential hashes from JSON serialization.
- Feature regression tests cover missing parent auth, family isolation, replay, expiry, refusal, device status, parent revoke, self-disconnect.

**Not yet implemented or tested:** a visible Android approval UI, safe Android Keystore credential storage, parental code creation screen, parent device list/revoke UI, actual device/emulator integration, location/activity collection, or screen sharing. No monitoring permissions are added. The JSON approve flag is supplied by a future Android UI and is **not proof of physical user consent by itself**. Do not claim real device-side authorization until tested on a device.

**Security review needed:** invitations are bearer secrets, anyone possessing a valid invitation can try to pair; do not disclose them in screenshots/logs. Companion pairing should be connected to a visible approval screen before production. Consider parent-side final confirmation, replay/concurrency stress tests, pairing abuse controls and device credential rotation. Review credential revocation across all future companion endpoints before adding any data ingestion. Use HTTPS in deployment; HTTP loopback is development-only.

**Verification:** This PR was authored via GitHub connector without local PHP, PostgreSQL or Android build execution in this session. It MUST remain draft until local Codex runs the PHPUnit suite on isolated SQLite and PostgreSQL databases, reviews migrations on a fresh test database, and fixes any failures. GitHub Actions is separately blocked by the account billing restriction.

Next: local code/test verification and then separate Angular/Android pairing UI with active consent and credential storage. Keep main unchanged.
