# Roadmap
Phase 0: inspect Windows, install compatible tooling, verify local prerequisites. Acceptance: tool versions recorded; any missing SDK/database setup explicit.
Phase 1: shells, routing, health, schema, contracts, scripts, documentation. Acceptance: Angular build/tests, Laravel health/tests and PostgreSQL migrations, Android debug build on configured SDK. Setup report is authoritative for executed checks.
Phase 2: Firebase identity and family policies. Acceptance: signature verification and cross-family/role denial tests.
Phase 3: consent-based device pairing and credential rotation. Acceptance: one-use pairing, expiry, revoke/unpair tests and visible device state.
Phase 4: opt-in minimized usage/location with validated ingestion, retention and deletion. Acceptance: no data after revocation; deduplication and background limits verified.
Phase 5: approved WebRTC screen sharing. Acceptance: fresh MediaProjection prompt each session, foreground Stop, expiry, no capture reuse.
Phase 6: production hardening, accessibility, Play policy/legal review, monitoring and disaster recovery. No feature advances before prerequisite authorization and consent tests pass.
