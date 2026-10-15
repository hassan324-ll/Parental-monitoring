# API contract

## Implemented in Phase 2A

- GET /api/v1/health — public; 200 {"status":"ok"}.
- GET /api/v1/families — authenticated; lists families visible to the requesting user (20 per page).
- POST /api/v1/families — authenticated; validates name (2–100 chars), creates family + owner membership transactionally; HTTP 201.
- GET /api/v1/families/{family}/children — authenticated owner/member; paginated.
- POST /api/v1/families/{family}/children — owner/parent membership only; validates display_name (1–100 chars); HTTP 201.

## Authentication limitation (blocking real client integration)

Laravel auth middleware currently rejects anonymous HTTP callers. Feature tests exercise authorization using Laravel actingAs(). Firebase bearer-token verification and identity mapping are NOT implemented. Do not expose these family APIs to production or claim Angular can sign in before a maintained Firebase verification SDK validates token signature, issuer, audience and expiry. Never add an anonymous development bypass.

## Authorization behavior

- Out-of-family family IDs: 404, including nested child requests.
- Viewer family members: can list children but receive 403 on create.
- Invalid inputs: 422. Unauthenticated: 401. Rate-limited: 429.
- The caller does not control owner_id or child-family membership.
- Family creation also creates the owner membership atomically.

## Planned, not implemented

Firebase sign-in, /api/v1/me, pairing, device credentials, permission records, usage ingestion, GPS locations, screen-share sessions, alerts and audit logs. Companion calls must use independently revocable device credentials.
