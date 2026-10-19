# Phase 4C foundation — bounded coarse location ingestion

This is an internal, **unrouted** service and its isolated regression tests. There is no location ingestion HTTP endpoint, activation route, collector or Android permission request. Existing `collection_available:false` remains unchanged. Native/verified-parent/UI/CI acceptance still gates activation/release. No development/production migration or data command was run.

## Activation boundary

`LocationIngestionService::store(deviceId, presentedCredentialHash, payload)` reauthenticates under the existing device row lock. It requires enabled, non-revoked location consent with a grant timestamp and a distinct future `location-collection-v1` disclosure. Existing device API accepts only `sharing-v1` preference decisions, so those saved flags can never authorize this service. Tests seed the future grant directly in isolated databases; no user grant UI or activation path exists yet. Enabling collection later requires reviewed fresh disclosure, explicit on-device choice, actual Android permission acceptance and corresponding tests. Do not add a route that bypasses this requirement.

The service's identity parameters are internal trusted context; a future controller must derive them from device middleware, never payload ownership IDs. Wrong-device, rotated and revoked credentials are rechecked inside the transaction. Every returned response is only accepted/duplicate booleans, without coordinates or credential information.

## Minimized sample rules

- One sample per call with exactly consent_version, recorded_at, latitude, longitude and accuracy_meters. Additional fields/target IDs/content/batches are rejected. Strict finite numeric coordinates and JSON integer version/accuracy, bounds and exact UTC second timestamp format. Shared sample schema documents the future shape; it is not an activated endpoint or runtime schema validator.
- Require current consent version, timestamp at/after current grant, strictly newer than rolling 24-hour cutoff, and no future timestamp. The grant-time equality is accepted. Time/version validation occurs before deduplication, preventing an old grant's replay from bypassing authorization.
- Round coordinates to two decimal places before storage (roughly kilometre scale depending on latitude), floor reported accuracy to 1000m; do not store original precision. Input accuracy maximum 100000m. These remain sensitive location coordinates: no claim of anonymization, independent accuracy verification or proof of a real sensor measurement.
- Duplicate device/timestamp with the same minimized sample is idempotent. A different minimized sample at the same time is conflict409. New samples must arrive in order at least15minutes after the latest accepted sample; no historical offline backfill behind that sample. This limits retained frequency to at most96 distinct samples per rolling24hours through this service. Device locking serializes deduplication, rate spacing, rotation, stop/delete, revocation and retention work.
- Expired rows are pruned when a new sample is accepted; existing stop/delete/revoke immediately purge all device location rows. The separate prune command still needs reviewed operational scheduling before release. No production scheduler was added.

## Verification scope

Nine feature tests cover minimized persistence/response, preference-only denial, idempotent/conflicting replay, strict field/type/bounds/date validation, grant/version/retention/future fences, exact fifteen-minute spacing/in-order behavior and expired cleanup, rotated/cross-family identity, stop/revoke non-resurrection, and absent public ingestion/activation. Three actual PostgreSQL two-process races cover duplicate insertion, stop/delete versus ingest, and revoke versus ingest. All samples are synthetic near zero coordinates. No real person/device location was collected.

The first targeted run exposed PHP8.5's NaN coercion warning in subsequent Laravel numeric validation after our custom finite check rejected it. Added per-field bail rules so invalid types/finiteness stop subsequent conversion; final affected checks must pass before publication. JSON itself cannot represent NaN; direct internal callers are still rejected safely.

Usage aggregate ingestion, fresh activation contracts/UI, permission handling, real device collection/background/battery behavior, parent views and production retention scheduling remain unimplemented. This milestone does not complete Phase4 or close Phase3 acceptance gates.