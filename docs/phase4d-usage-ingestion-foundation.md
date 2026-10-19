# Phase 4D foundation — cumulative daily usage ingestion

Internal service and isolated tests only. No ingestion or activation HTTP endpoint, Android permission, app inventory query, usage collector or background service is enabled. `collection_available:false` remains unchanged. Existing `sharing-v1` preferences cannot authorize collection; the service requires a distinct future `usage-collection-v1` enabled grant and current version. Tests seed that grant only in isolated databases. Native, verified-parent/browser/inbox and hosted CI acceptance remain open.

## Snapshot semantics

One request shape is a complete cumulative UTC-day snapshot: consent_version, day, recorded_until and apps containing package_name/duration_seconds only. Ownership comes from authenticated internal device context, never the payload. A future HTTP boundary must use device middleware/FormRequest, body-size/rate limits and actual contract tests before exposing it.

The observation window begins at the later of UTC day start and the current grant, ending at recorded_until (which may equal next UTC midnight for a completed day). The day must be one of seven UTC calendar dates including today; time must be after the window start and never beyond the day end or server now. No pre-grant/day data may be included by a future collector. These are validation bounds, not proof of a trustworthy sensor or honest device report.

At most100 unique package names, each restricted ASCII dotted identifier up to150characters, with strict positive integer foreground seconds at most86400. Zero-second entries are rejected to avoid turning usage into an installed-app inventory. Empty apps is allowed for no foreground use. No app titles, content, events, URLs or private messages. Total foreground time across all apps cannot exceed the authorized window: the future collector must count at most one foreground app per second. The schema documents the shape; application validation enforces uniqueness/time/current-grant semantics.

Retries are snapshots, not deltas: exact canonical package totals at the same recorded_until return duplicate:true without another write. Reordered packages are equivalent; conflicting same-time totals or older timestamps are409. Newer snapshots must retain every prior package with nondecreasing totals, and their combined increase cannot exceed elapsed seconds since the previous snapshot. More than100 cumulative apps in a day is rejected atomically; never silently discard old packages or content to bypass the cap. Empty snapshots still retain a time watermark. There is no partial batch acceptance.

## Persistence and deletion

Existing app_usage_daily rows retain only package/day/duration for the device. New usage_ingestion_snapshots stores device/day/current consent version/recorded_until and timestamps, with a unique device/day constraint and device deletion cascade. It contains no raw events or full payload. Both tables are updated atomically under the existing device row lock, shared with rotation, revocation, stop/delete and retention.

An existing watermark from a different grant, or old totals without a watermark, are rejected for explicit recovery rather than being silently attributed to a new grant. A future activation flow must clear old feature data/watermarks before creating its fresh grant. Current preference/activation APIs were not expanded.

Stop/delete, parent revoke and device disconnect purge totals and watermarks together; disabled-device retention purge does the same. Insertion and the prune command remove both tables before the seven-date cutoff. Operational scheduling and actual volume/device acceptance are still pending. The new migration was exercised only in isolated SQLite/PostgreSQL tests, never development/production.

## Verification

Feature regressions cover retries and cumulative replacement, empty snapshots, preference-only denial, stale/conflicting/omitted/decreasing/impossible increments, strict types/fields/package bounds, consent version/grant/day/time/retention limits, 100-package acceptance, metadata retention/deletion, cross-family/rotation denial, old-grant and untracked legacy totals, cascade and migration rollback/reapply, and absent public activation/ingestion routes. Three real PostgreSQL two-process cases cover duplicate insertion, Stop-and-delete versus ingestion and revocation versus ingestion. All app names/totals are synthetic.

This milestone does not activate collection, complete Phase4, or close native/Firebase/browser/CI release gates. Future native collection needs fresh explicit activation plus Android usage-access acceptance and visible Stop; existing saved preferences cannot silently start it. Future parent views must enforce family authorization and retention filters even before scheduled pruning runs.