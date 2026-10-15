# FamilyGuard

Transparent Android family safety foundation, consisting of an Angular parent dashboard, Laravel API and Kotlin Compose companion app. No monitoring or screen capture is active.

## Project status

- Phase 1: Angular shell, public Laravel health endpoint, PostgreSQL schema, Kotlin companion shell and verified local Android debug APK. See docs/setup-report.md.
- Phase 2A: Family and child REST endpoints on a separate draft PR with family membership authorization and tests. These have not passed CI yet.
- Phase 2B (stacked draft branch): Parent web sign-up, email verification, sign-in, password reset, route guards, and logout scaffolding using Firebase's modular client SDK. Firebase web configuration defaults to null, intentionally disabling real sign-in until configured. Laravel Firebase token verification is not implemented, so protected API access remains blocked.
- Planned: server Firebase verification, device pairing, permitted usage summaries, authorized GPS sharing and per-session consent-based screen sharing.

## Local structure

apps/parent-dashboard, apps/backend, apps/child-android, packages/shared-contracts, docs, scripts, infrastructure/local, .github/workflows.

## Running locally

From the repository root in PowerShell:

~~~powershell
.\scripts\check-environment.ps1
.\scripts\setup-project.ps1
.\scripts\start-database.ps1
.\scripts\start-backend.ps1
# Separate terminal:
.\scripts\start-frontend.ps1
.\scripts\run-tests.ps1
~~~

Laravel: http://localhost:8000; health: /api/v1/health. Angular: http://localhost:4200. Configuration steps: docs/firebase-setup.md. GitHub Actions troubleshooting: docs/ci-troubleshooting.md. No private data or Firebase service credentials belong in the repository.

Do not deploy to production until server identity verification, device consent, authorization tests, and privacy requirements are complete.
