# FamilyGuard

Transparent Android family safety foundation, consisting of an Angular parent dashboard, Laravel API and Kotlin Compose companion app. No monitoring or screen capture is active.

## Project status

- Phase 1: Angular shell, public Laravel health endpoint, PostgreSQL schema, Kotlin companion shell and verified local Android debug APK. See docs/setup-report.md.
- Phase 2A: Family and child REST APIs with membership authorization.
- Phase 2B: Firebase parent registration, verified-email sign-in, password reset and guarded dashboard.
- Phase 2C: Laravel verifies Firebase RSA signatures and identity claims before protected API access.
- Phase 2D: Development Firebase project connected; `/api/v1/me` and dashboard identity check implemented. Local automated and real-provider results: docs/phase2d-live-verification-report.md. Verified inbox completion still requires a human. Production authentication remains disabled.
- Planned: Consent-based device pairing, then separately authorized usage summaries, GPS and screen sharing.

## Local structure

frontend, backend, apps/child-android, packages/shared-contracts, docs, scripts, infrastructure/local, .github/workflows.

## Simple development commands

Open the FamilyGuard root folder in VS Code and create two **new** terminals. Workspace terminal settings select the project PHP (with PostgreSQL support) and local Angular CLI. Start the database once with `.\scripts\start-database.ps1`.

Terminal 1:

~~~powershell
cd frontend
ng serve
~~~

Terminal 2, starting at the FamilyGuard root:

~~~powershell
cd backend
php artisan serve
~~~

Keep both terminals open; Ctrl+C stops the respective service. Outside VS Code, first run `. .\scripts\common.ps1` from the root in each PowerShell terminal to select the same local runtimes. The database must be running.

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
