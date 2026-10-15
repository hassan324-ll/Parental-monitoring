# Architecture
Angular → Laravel REST → PostgreSQL; Android → the same API. Firebase will issue parent identity tokens and deliver FCM notifications. Laravel owns family membership, policy enforcement, pairing and audit events. Clients never decide authorization. Firebase is not the monitoring data store.

Only GET /api/v1/health is implemented. All feature screens are placeholders. PostgreSQL schema is a foundation; services, FormRequests and policies precede any protected route. Family and device composite foreign keys prevent cross-family child assignment. Services must additionally verify requestor membership.

Future WebRTC: parent request → server verifies pairing → device accepts → fresh MediaProjection system consent → foreground notification → short-lived signaling and TURN credentials → encrypted media. No recording by default. Stop on revocation, expiry, disconnect or device stop. Never reuse capture consent. PWA: standalone Angular and environment files are ready for later service worker setup; do not cache private API data.
