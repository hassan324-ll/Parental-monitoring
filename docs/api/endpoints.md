# API contract
Implemented: GET /api/v1/health, public, 200 application/json, {"status":"ok"}. No database details. Local CORS allows http://localhost:4200.

Planned, NOT implemented: /api/v1/me; /families; /families/{family}/children; /devices; /device-pairings; /devices/{device}/permissions; /devices/{device}/usage; /devices/{device}/locations; /devices/{device}/screen-share-sessions; /alerts; /audit-logs.

Protected calls require Authorization: Bearer <Firebase ID token> for parents, separately scoped device credentials for companions. Verify signature/audience/issuer/expiry before mapping Firebase UID. Request validation returns 422, missing identity 401, forbidden family 403 or concealed 404, rate limiting 429. Future success envelope: {"data":...}; errors: {"message":"...","errors":{}}. Never return stack traces or secrets. Pagination and idempotent device upload keys required before ingestion.
