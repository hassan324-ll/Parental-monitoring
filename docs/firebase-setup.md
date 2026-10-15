# Firebase parent authentication

FamilyGuard uses Firebase's modular JavaScript client for the parent sign-in interface. This branch adds a Laravel Firebase ID token verifier backed by Google's published signing certificates; no private cloud key is required just to validate incoming ID tokens.

## Account configuration required

1. Create/select a Firebase project and enable Authentication > Sign-in method > Email/Password.
2. Register a Firebase Web app, then configure Angular's environment.firebaseConfig with the public web settings. Keep service accounts and private keys out of Angular and Git.
3. Set FIREBASE_PROJECT_ID in the ignored Laravel .env file to the **exact** projectId for the registered Firebase app.
4. Run new database migrations locally to add users.firebase_uid.
5. Test registration, verification email, login and authenticated family creation with synthetic test accounts.

## Backend verification

The Laravel firebase-parent request guard rejects missing, malformed, unsupported, expired and incorrectly signed JWTs. It checks RS256 signature against the Google securetoken certificates (downloaded via HTTPS and cached for 30 minutes), project audience, issuer, token timestamps, UID and verified email. A missing FIREBASE_PROJECT_ID fails closed.

The verified Firebase UID, **not a browser-supplied user ID or matching email**, is the link to a Laravel user. The first verified sign-in creates a local record with a random unusable password. An existing local user with the same email but no matching Firebase UID is **not silently linked**; manual migration is required to avoid account takeover.

## Remaining checks

- GitHub Actions is blocked because of an account billing lock; see docs/ci-troubleshooting.md.
- Run full Laravel tests on SQLite and a separate PostgreSQL test database before merging.
- Test Firebase real-provider sign-up, verified email, and REST calls after configuring the project.
- The backend guard verifies token signature and claims, but **revoked-session checks through Firebase Admin are not yet implemented**. Firebase project administration, certificate refresh/failure handling, multi-process caching and device pairing require additional hardening and testing.
- This auth guard is **parent-only**. Android companion devices will use separate revocable device credentials after explicit consent-based pairing.
