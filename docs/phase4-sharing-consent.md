# Phase 4A — device-owned sharing consent and deletion

The human explicitly requested the next development phase. Pairing, authorization, consent and revocation automated checks pass locally, including real JVM-to-Laravel HTTP. This branch begins backend Phase4 development; Phase3 actual native/browser/Firebase/CI acceptance remains a release gate. No background collection, sensor/usage permissions, ingestion endpoint or parent data view is implemented by this slice.

## Current API

Device bearer authentication is required for all calls. A parent Firebase identity alone cannot enable device sharing. Ownership comes only from the authenticated device, never a submitted family/child/device ID.

- GET `/api/v1/companion/sharing`: separate usage/location consent status; missing rows mean disabled/version0. No private metrics, locations or credential hashes returned. Cache-Control:no-store.
- PUT `/api/v1/companion/sharing/{usage|location}`: strict JSON boolean `enabled`. Enabling additionally requires `disclosure_version: sharing-v1`. Client target IDs and version overrides rejected. This records a device assertion of consent, not proof of a physical tap or Android system permission.
- DELETE `/api/v1/companion/sharing/{usage|location}`: stop-and-delete for the calling device. Submitted target IDs cannot select another device. PUT enabled:false has the same stop/purge behavior.

Every decision increments its server version, including repeated decisions, so future ingestion can fence out queued batches from earlier grants. Features are independent. New responses always include `collection_available:false`; an enabled consent flag must not be displayed as active collection. Android preference/deletion controls are now implemented in [Phase 4B](phase4b-android-sharing-controls.md); Angular controls and all collectors remain unimplemented.

## Locking and privacy

Status/decisions reauthenticate the current credential under the same device row lock used by rotation and revocation. A middleware snapshot or rotated/revoked bearer cannot authorize a stale mutation. Parent revoke/device disconnect disables all stored consents, increments versions, and purges usage/location rows in the same transaction as credential revocation. Disabled feature data is erased immediately for that device only. A PostgreSQL two-process test verifies opt-in cannot survive concurrent revocation.

New `device_sharing_consents` migration has device/feature uniqueness, default-false enabled, server version, current disclosure and latest grant/revoke timestamps; no full consent-event history or private content. Deleting devices/families cascades consent rows. Migration is tested only in isolated databases; development/production schemas have NOT been migrated. This branch requires reviewed migration rollout before use in a real environment.

## Retention

`php artisan familyguard:prune-sharing` purges all stored usage/location rows for disabled or revoked devices, and prunes enabled data: usage retains seven UTC calendar dates including today (older day rows deleted), location retains less than24hours (cutoff exactly24hours deleted). The command locks each device while deciding, scans in batches of100, and never reports private data. It was executed only inside isolated test cases. No scheduler/deployment was changed; an operational schedule and production volume acceptance are still required before ingestion release. Stop/delete/revoke purges are immediate and do not wait for this command.

## Verification and remaining slices

Ten feature tests cover default-off, separate parent/device identity, strict disclosure/boolean/ownership validation, monotonic versions, feature/family isolation, DELETE targeting, stale-rotation denial, parent/device revoke cleanup, retention boundaries and migration rollback/reapply. An additional actual PostgreSQL race covers concurrent opt-in/revocation. All fixtures are synthetic; no real usage/location was collected.

Next Phase4 slices:

1. Android preference/deletion development implemented in Phase 4B; actual native/UI acceptance remains pending. No collection while collection_available:false. Future activation must require a new disclosure and explicit choice; sharing-v1 preferences cannot silently enable collection.
2. Unrouted coarse-location foundation implemented and tested in [Phase4C](phase4c-location-ingestion-foundation.md), with current-version/grant/time fences and deduplication/stop/revoke races. Usage snapshot foundation is implemented in [Phase4D](phase4d-usage-ingestion-foundation.md); fresh activation remains pending. No endpoint or collector can use sharing-v1 preferences as activation.
3. Opt-in native collection within Android background limits, minimized daily usage aggregates and coarse location, visible indicators and immediate stop. No private messages, keystrokes, hidden collection or permission bypass.
4. Family-scoped backend summary reads implemented in [Phase4E](phase4e-family-sharing-summary.md); Angular presentation, operational retention scheduling and actual device/background/battery acceptance remain pending. Phase5 sharing remains separate and requires fresh MediaProjection approval per session.

Phase4 is started, not complete. Native9tests, verified-parent/browser/inbox, actual Android HTTP/Keystore/UI/process-death and GitHub billing-unlocked CI remain outstanding. No main merge or production deployment.
