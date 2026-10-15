# Firebase project integration — Phase 2D

The user registered a Firebase Web app and enabled Firebase Authentication > Email/Password. The public web app configuration now exists in the **development Angular environment** for Firebase project `family-guard-eb08c`. The production Angular environment remains unconfigured and therefore refuses authentication until intentionally set up. A Firebase Web API key is a public project identifier, not an Admin SDK private key; still apply suitable API key restrictions, Firebase Authorized Domains, and abuse protections.

## Required local Laravel action

On the user's laptop, edit ignored `backend/.env` and set:

```dotenv
FIREBASE_PROJECT_ID=family-guard-eb08c
```

Never change the local database credentials based on this document. Restart Laravel (and clear config cache if used). The backend can then validate signed parent ID tokens for this specific project. The new `GET /api/v1/me` endpoint returns a minimal parent profile after verified Firebase authentication and provides an end-to-end smoke test of the Angular -> Firebase -> Laravel chain.

## Run and verify (local account/testing action)

1. Ensure Firebase Authentication > Email/Password is enabled and localhost is permitted in Firebase Authentication's authorized domains when necessary.
2. In `frontend`, install locked dependencies and run the Angular development server using the existing project scripts.
3. Run the Laravel API with a valid PostgreSQL development configuration and the project ID above. Apply additive pending migrations as needed, without resetting the development DB.
4. Register a **synthetic test parent** in the browser, check its inbox for email verification, then log in.
5. Open the Angular dashboard and click **Verify with Laravel**. This invokes `GET /api/v1/me` with a Firebase ID token; the result should display a minimal test-parent name.
6. Try an unverified account, a missing token, an invalid token and a different Firebase project's token; all must receive 401 from the API. Check that only the own family is accessible.
7. Execute local `npm test`, `npm run build`, strict TypeScript checks, and isolated SQLite and PostgreSQL backend tests. Do not run destructive migrations against the `familyguard` development DB.

## Remaining production blockers

- Live SDK registration/sign-in, verification/reset request acceptance, logout, and rejection of an unverified real-provider token have passed. Disposable test accounts were deleted. Inbox delivery, email-link completion, browser persistence for a verified parent and real-provider authenticated API success still require manual verification. See phase2d-live-verification-report.md and phase2d-live-testing-guide.md.
- Immediate Firebase revoked-token and disabled-account checks via trusted administrative verification remain required before release.
- Production Firebase config is intentionally null; configure production domains, HTTPS and server project environment explicitly later.
- Google-hosted CI remains blocked due to the repository owner's billing restriction. Continue reporting real local test evidence.
- Android device pairing, usage monitoring, GPS and screen sharing are NOT included in this phase.
