# API contract

## Implemented in Phase 2A

- GET /api/v1/health — public; 200 {"status":"ok"}.
- GET /api/v1/families — authenticated; lists families visible to the requesting user (20 per page).
- POST /api/v1/families — authenticated; validates name (2–100 chars), creates family + owner membership transactionally; HTTP 201.
- GET /api/v1/families/{family}/children — authenticated owner/member; paginated.
- POST /api/v1/families/{family}/children — owner/parent membership only; validates display_name (1–100 chars); HTTP 201.

## Parent authentication

The firebase-parent request guard verifies RS256 signatures against Google's HTTPS signing certificates, project audience, issuer, lifetime, UID and verified-email claims. Tokens are mapped by immutable Firebase UID; matching email alone never links a local account. Missing configuration or invalid tokens fail closed with 401. The frontend auth UI requires actual Firebase project configuration before it can sign in.

Certificate cache lifetime, signing-key rotation and an IP throttle before authentication are covered by local tests. Parent endpoints share a 60-request/minute/IP limit, including invalid attempts; deployments behind a proxy must configure trusted proxy handling correctly and account for shared-IP traffic. Revoked/disabled Firebase sessions are not checked through Firebase Admin yet, so a previously issued valid token can remain usable until its expiry. Do not claim immediate session revocation or production readiness.

## Authorization behavior

- Out-of-family family IDs: 404, including nested child requests.
- Viewer family members: can list children but receive 403 on create.
- Invalid inputs: 422. Unauthenticated: 401. Rate-limited: 429.
- The caller does not control owner_id or child-family membership.
- Family creation also creates the owner membership atomically.

## Planned, not implemented

/api/v1/me, pairing, device credentials, permission records, usage ingestion, GPS locations, screen-share sessions, alerts and audit logs. Companion calls must use independently revocable device credentials.
