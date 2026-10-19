HTTP contracts are transport-only. No credentials, platform code or business rules. Health schema matches GET /api/v1/health.
Device rotation request/response schemas describe the hash-only backend contract. They contain no actual credentials. See docs/phase3c-credential-rotation.md for required client staging and lost-response recovery; client rotation is not yet enabled.
