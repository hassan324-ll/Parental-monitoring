# Phase 2E — Family management Angular UI

This draft change builds on Phase 2D and preserves the migrated repository structure:

- Angular in frontend/
- Laravel in backend/
- Android in apps/child-android/

The code provides a My Children route with a parent-only view of the family/child REST APIs. It does not include app monitoring, private-message capture, Android pairing or GPS collection.

## Added frontend features

- Typed FamilyApiService for GET/POST /api/v1/families and GET/POST /api/v1/families/{familyId}/children. The existing Firebase interceptor authenticates requests to the configured Laravel API.
- Family creation with validation, family listing and family selection.
- Child profile creation/listing scoped to the selected family.
- Pagination for families/children, loading indicators, access/network errors, responsive layouts and accessible form labels.
- Tests for HTTP request shape and component family/child workflows with synthetic data only.

The Laravel backend already enforces authenticated owner/member access and denies cross-family access; this UI adds no separate authorization bypass.

## Current limitations

- This branch has NOT been locally built/tested by this GitHub editing session. Local Codex should verify and fix Angular template/style/spec compilation before this PR is considered mergeable.
- A Firebase-account inbox must still complete verified email login; only then can an actual parent create a test family in the browser.
- The existing Laravel API supports creation/listing only, not updating/deleting family/child records or inviting guardians.
- No child device pairing is implemented; child rows intentionally show "No device paired".
- Hosted GitHub Actions remains blocked by the confirmed account billing lock.

## Windows verification commands

Open the existing project in VS Code and pull the Phase 2E branch safely, preserving uncommitted local files. Use the existing backend/ database configuration.

From the project root in one terminal:

    cd frontend
    npm test -- --watch=false
    npm run build

From another terminal at the project root:

    cd backend
    php artisan test

Use the repository's existing scripts/run-tests.ps1 for isolated SQLite/PostgreSQL test databases and project-local PHP. Never reset the development database.

For manual UI testing, start services with the existing scripts/start-familyguard.ps1, sign into a verified synthetic parent account, and open http://localhost:4200/children. Create a family, add a synthetic child name and confirm pagination and reload. Try an unauthorized account and ensure it cannot read another family's data. Do not enter real children's information until security review and release requirements are complete.

## Recommended next gate

Run local Angular/PHP suites and signed-in browser tests on Phase 2E; fix any failures; keep the pull request draft until verified. After that, consent-based Android device pairing can be implemented separately.
