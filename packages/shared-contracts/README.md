HTTP contracts are transport-only. No credentials, platform code or business rules. Health schema matches GET /api/v1/health.
Device rotation request/response schemas describe the hash-only backend contract. They contain no actual credentials. See docs/phase3c-credential-rotation.md for required client staging and lost-response recovery; client rotation is not yet enabled.

Pairing approval schemas: device-pairing-approval-request.schema.json and device-pairing-approval-response.schema.json. Optional staged-hash mode omits device_token; legacy mode returns it. See ../../docs/phase3c-staged-approval.md for cross-request semantics and unimplemented Android durable approval staging.
