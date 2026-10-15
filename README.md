# FamilyGuard
Transparent Android family safety foundation. Phase 1 includes a parent Angular shell, Laravel API health endpoint and Kotlin Compose companion shell. Pairing, authentication and monitoring are planned, not implemented.

Stack: Angular 22 standalone/strict SCSS with Material; Laravel 13/PHP 8.5; PostgreSQL; Kotlin/Compose Material 3, MVVM, Flow, Retrofit, WorkManager; future Firebase Auth/FCM. See docs/setup-report.md for the initial setup history and apps/child-android/ANDROID-BUILD-RESULT.md for the successful Android debug build and launch instructions.

Structure: apps/parent-dashboard, apps/backend, apps/child-android; packages/shared-contracts; docs/{architecture,api,database,android,security,testing}; scripts; infrastructure/local; .github/workflows.

From this repository in PowerShell:
~~~powershell
.\scripts\check-environment.ps1
.\scripts\setup-project.ps1
.\scripts\start-database.ps1
.\scripts\start-backend.ps1
# A separate terminal:
.\scripts\start-frontend.ps1
.\scripts\run-tests.ps1
~~~
Backend http://localhost:8000; health /api/v1/health; dashboard http://localhost:4200. Scripts bind to loopback. Copy backend .env.example to .env if absent, generate APP_KEY, the generated local PostgreSQL role/database is already configured on port 5433; use .tools/php/php.exe for manual artisan commands. For a new clone, create a dedicated role/database and put credentials only in ignored .env. No global Git identity is changed. Setup does not silently migrate or overwrite environments.

Android setup: docs/android/permissions.md. Firebase account steps: docs/firebase-setup.md. Security: docs/security/architecture.md. PWA support is planned; no service worker caches private data. Do not publish the release app until production HTTPS and identity verification are implemented.
