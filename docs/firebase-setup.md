# Firebase parent authentication

FamilyGuard uses Firebase's modular JavaScript client for the parent sign-in interface. This branch adds a Laravel Firebase ID token verifier backed by Google's published signing certificates; no private cloud key is required just to validate incoming ID tokens.

## Account configuration required

1. Create/select a Firebase project and enable Authentication > Sign-in method > Email/Password.
2. Register a Firebase Web app, then configure Angular's environment.firebaseConfig with the public web settings. Keep service accounts and private keys out of Angular and Git.
3. Set FIREBASE_PROJECT_ID in the ignored Laravel .env file to the **exact** projectId for the registered Firebase app.
4. Run new database migrations locally to add users.firebase_uid.
5. Test registration, verification email, login and authenticated family creation with synthetic test accounts.

## Backend verification

The Laravel firebase-parent request guard rejects missing, malformed, unsupported, expired and incorrectly signed JWTs. It checks RS256 signature against the Google securetoken certificates (downloaded over HTTPS without redirects and cached no longer than the response's max-age minus Age, capped at one hour), project audience, issuer, token timestamps, UID and verified email. A missing FIREBASE_PROJECT_ID fails closed. Certificate refreshes use a cache lock; an unknown signing key can refresh after a 60-second cooldown. Expired caches are not reused on network failure. Multi-host deployments require a shared lock-capable cache. The parent API limits authentication work to 60 requests per minute per IP before resolving a bearer identity.

The verified Firebase UID, **not a browser-supplied user ID or matching email**, is the link to a Laravel user. The first verified sign-in creates a local record with a random unusable password. An existing local user with the same email but no matching Firebase UID is **not silently linked**; manual migration is required to avoid account takeover.

## Remaining checks

- GitHub Actions is blocked because of an account billing lock; see docs/ci-troubleshooting.md.
- Local SQLite and dedicated PostgreSQL verification results are recorded in docs/verification-report.md. Run both suites after subsequent changes.
- Test Firebase real-provider sign-up, verified email, and REST calls after configuring the project.
- The backend guard verifies token signature and claims, but **revoked-session checks through Firebase Admin are not yet implemented**. Firebase project administration, deployment-specific multi-process/load testing and device pairing remain additional work. Certificate refresh/failure handling and deterministic uniqueness-race behavior are covered by local regressions; that is not a distributed load test.
- This auth guard is **parent-only**. Android companion devices will use separate revocable device credentials after explicit consent-based pairing.

## PHP HTTPS trust on Windows

PHP must have a current trusted CA store for the Google certificate download. The local PHP runtime was missing this configuration and returned cURL error 60. The checksum-verified Mozilla CA bundle from https://curl.se/ca/cacert.pem was installed only in ignored .tools/php and configured through curl.cainfo and openssl.cafile in that local php.ini. Restart PHP after changing its ini. The real HTTPS endpoint then returned 200 and valid signing certificates. Fresh clones must configure an equivalent trusted CA bundle; never disable TLS verification or use verify=false.
