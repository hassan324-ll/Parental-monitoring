# Phase 4B — visible Android sharing preferences

Activity and Location settings now use the device-bearer sharing API independently. This is preference/deletion development only: `collection_available:false`, no collection, no sensor/usage permission requests, no background service and no parent data view. Existing Phase 3 native/Firebase/browser/CI acceptance remains open.

## Device experience

Verify the device session, then open Activity or Location Sharing Settings. Refresh shows a server-verified default-off or saved preference, never an active-collection indicator. Review and save requires a fresh status revision, an unchecked acknowledgment and a separate Save preference confirmation. Cancel/dismiss sends no decision. The disclosure explicitly requires a new choice before collection can start in any future version; the saved `sharing-v1` flag must never silently activate a future collector. Future ingestion/activation work must enforce a new activation disclosure rather than treating this preference as operating-system permission.

Stop and delete has its own confirmation and requests DELETE for this device's selected feature only. It stays explicitly retryable after an unknown status while device authorization remains verified. Offline, interrupted or malformed responses clear the previous snapshot and report an unknown server result; they never claim that deletion succeeded. A rejected bearer invalidates session authorization and blocks further changes until reverified. No bearer enters UI state or logs.

## State and transport

Sharing operations and credential restore/approval/rotation/disconnect use the same DeviceSession mutex. Identity operations invalidate sharing snapshots and open-dialog revisions. A stale or repeated confirmation cannot send another opt-in. A decision response must match the requested flag and advance the previously known server version. Preferences are not cached to disk; session reconstruction requires fresh authorization and sharing status.

The HTTP adapter accepts only the supported disclosure and disabled collection capability, real booleans, nonnegative integer versions, both features and bounded nonblank retention text. PUT sends only enabled:true and disclosure_version; DELETE has no submitted ownership. Fixed-length streaming is used for PUT as well as POST, preserving the existing protection against implicit buffered write replay. No redirects/caching; existing response size/UTF-8 bounds and bearer handling remain.

## Acceptance boundaries

JVM coordinator tests exercise authorization, explicit acknowledgment, stale/repeated confirmation, offline ambiguity/retry, cancellation, revocation, identity invalidation and disconnect serialization. Loopback tests exercise real JVM HTTP/JSON and malformed contracts. The opt-in Laravel acceptance test exercises default-off, independent preference save and Stop-and-delete before rotation/disconnect against isolated PostgreSQL fixtures.

These checks do not execute Android platform JSONObject/networking, Compose taps/accessibility, Keystore, actual process death or system permissions. Native instrumentation remains compiled but unexecuted while Windows emulator acceleration/device availability is unresolved. GitHub-hosted jobs remain blocked by the confirmed account billing lock. No development/production migration, collector activation or release acceptance is implied.

Next bounded slice: minimized consent-version-fenced ingestion with strict bounds, deduplication, timestamp/retention fences and revoke/delete concurrency regressions. Keep collectors disabled and require a fresh activation disclosure before any real data can be accepted from a future collector.