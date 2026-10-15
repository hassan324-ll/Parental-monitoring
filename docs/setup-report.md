# FamilyGuard setup report
Date: 15 October 2026 (Asia/Karachi).

Project directory: `C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard`

Overall: **Partially completed**. Real Angular, Laravel, PostgreSQL and Kotlin source projects were created. Web/API/database checks passed. Android Studio/JDK/Gradle are installed and verified; the first-run SDK wizard still needs completion, so no APK was built. No monitoring functionality, cloud project or paid resources were created.

## Environment inspection
Windows 10 Pro 22H2, build 19045, x64; Intel Core i7-6600U @2.60 GHz, x64. RAM: 8,058,140 KiB (~7.69 GiB usable); initially 989,528 KiB free. Final check with Android Studio open: about 264 MiB free. C: initially ~40.4 GiB free; D: ~95.5 GiB free. Hardware virtualization and second-level address translation are enabled. Hypervisor acceleration is **not tested**. No BIOS or security settings changed. PowerShell 7.6.5; native Windows PowerShell 5.1 compatibility was targeted but not executed. Terminal commands are permitted; hardware queries, package networking and the requested Projects folder required sandbox escalation, which was obtained. No automatic approval rejection occurred.

| Software | Installed | Verified version | Required | Action/result |
|---|---|---|---|---|
| Git | Yes, existing | 2.56.0.windows.1 | Yes | Reused; repository initialized; no identity changes |
| Node.js | Yes, existing | 24.21.0 | Yes | Compatible with Angular 22.2.2 engines; reused |
| npm | Yes, existing | 11.19.0 | Yes | Used npm.cmd; enabled network only for task commands |
| Angular CLI | Existing global + local | Global package 22.2.1; local 22.2.2 | Local | Local CLI verified by successful builds |
| Angular framework | Yes, new project | 22.2.3 | Yes | Locked in package-lock.json |
| Material/CDK | Yes, project-local | 22.2.2 | Yes | Material theme/button used |
| PHP | Existing + local | Herd 8.5.0; official local 8.5.11 | Yes | Existing runtime lacks pdo_pgsql; local runtime has pgsql/sqlite drivers |
| Composer | Yes, existing | 2.8.12 | Yes | Reused; composer.lock installed and validated |
| Laravel installer | Yes, existing | 5.32.0 | No | Reused inspection only; Composer created backend |
| Laravel framework | Yes, project-local | 13.36.0 | Yes | Tested |
| PostgreSQL | Yes, installed via winget | 18.6 | Yes | Application database migrated successfully |
| Android Studio | Yes, installed via winget | 2026.2.1.8; build AI-262.9437.185.2621.16467767 | Yes | Executable/product metadata verified; opened SDK wizard |
| Android bundled Java | Yes | OpenJDK 25.0.3 | IDE only | Too new for Gradle 8.13; not used to run project Gradle |
| Project-local JDK | Yes, checksum verified | Temurin 17.0.20.1+1 | Yes | java -version and Gradle verified |
| Android SDK | No | — | Yes | First-run wizard at Select SDKs; manual action pending |
| ADB/platform tools | No | — | Yes | Pending SDK wizard |
| Android emulator/AVD | No | — | Optional | Not configured; prefer physical device with this RAM |
| Gradle | Yes, official wrapper | 8.13 | Yes | gradlew.bat --version passed; SHA-256 pinned |
| VS Code | Yes, existing | 1.141.1 x64 | Optional | Reused; no configuration modified |
| Firebase CLI | Yes, project-local | 15.34.0 | Preparation | Version verified; no login/init/deploy |
| Firebase JS SDK | Yes, project-local | 13.0.0 | Preparation | Declared; no cloud credentials or initialization |
| winget | Yes, existing | 1.29.380 | Install utility | Verified vendor package identities before installing |
| Chrome | Yes, existing | 155.0.8059.40 | Browser | Available; no interactive browser QA performed |
| Edge | Yes, existing | 155.0.4283.45 | Browser | Available |
| Microsoft C++ runtime | Yes, updated with approval | Package 14.51.36247.0 | PHP dependency | Installation reported success; official PHP now starts |

## Component status
| Component | Status | Evidence/limits |
|---|---|---|
| Environment and Git | Completed and verified | Versions, hardware, exclusions and repository inspected |
| Angular dashboard | Completed and verified | Production build, one Vitest test and localhost HTTP 200 |
| Laravel API foundation | Completed and verified | Health/CORS/schema/integrity tests, actual HTTP health response |
| PostgreSQL development setup | Completed and verified | pgsql connection, 20 migrated tables, non-superuser app role; six tests on separate test DB |
| Android Studio/JDK/Gradle | Completed and verified | IDE installed; Java and wrapper versions executed |
| Android Compose app source | Completed but not tested | Ten screens, MVVM/Flow/repository, icon, variants and manifest; native compile not executed |
| Android SDK/ADB/native build | Blocked | SDK/license wizard pending; no SDK, AVD, lint run or APK |
| Firebase configuration | Partially completed | Web/native packages and templates/docs exist; account/project registration pending |
| Developer scripts | Partially completed | All parsed; environment/start-backend/start-database executed; setup reinstall/stop-database/native build scripts not executed |
| Security documentation and roadmap | Completed but not tested | Design documents only; future auth/pairing/monitoring APIs not implemented |
| Authentication and monitoring features | Not started | Intentionally outside this initialization phase |

## What was initialized
Angular: standalone strict TypeScript, SCSS, Material, responsive navigation, eleven lazy-routed page placeholders, dashboard empty states, environment-specific API URL and health service. Production API URL is same-origin /api/v1; future deployment needs a reverse proxy. PWA foundation only; no service worker installed.

Laravel: public GET /api/v1/health returns only {"status":"ok"}; local CORS is fixed to http://localhost:4200; API errors render JSON; .env.example uses secure debug-off defaults. Family/Child/Device model relationships, service/request/policy/middleware/job/event/notification directories, and all requested tables are present. Users use Laravel's password hashing; no real seed data was inserted. Only health is public; protected routes await identity/policy implementation.

PostgreSQL: system service was installed separately on default port 5432. FamilyGuard uses its own SCRAM cluster at ignored .local/postgres on loopback port 5433. Database familyguard and least-privilege role familyguard are configured in ignored backend .env. Verification returned driver=pgsql, database=familyguard, rolsuper=false and 20 tables. familyguard_test is separate for integration tests. Generated admin credentials are only in ignored .local/postgres-admin.env. No passwords are printed in this report or stored in source.

Android: com.familyguard.child, debug suffix .debug, Compose Material 3, ten navigable screens, visible shield icon and app name, settings link for permission review, health connection ViewModel/repository using Retrofit, coroutine/Flow dependencies, WorkManager and Firebase Auth/FCM dependencies. Only INTERNET is declared. No monitoring jobs, GPS prompts or capture sessions exist. Debug uses 10.0.2.2; release has no API URL and rejects cleartext. Release distribution is not ready.

Firebase: no account access, cloud project ID, service account or billing resources created. Follow docs/firebase-setup.md when ready. API identity verification remains required before any protected endpoint.

## Actual checks and outcomes
| Check executed | Outcome |
|---|---|
| npm install | Dependencies installed; lockfile generated |
| npm run build | Passed; final initial bundle ~265.90 kB, lazy feature chunk ~54.82 kB |
| npm test -- --watch=false | Passed: 1 test, 1 test file |
| Angular development server | Started on localhost:4200; HTTP 200 verified |
| Composer create-project/install | Completed; Laravel scaffold 13.11.0, framework 13.36.0 |
| composer validate --strict | Passed |
| Laravel tests on SQLite | Passed: 6 tests, 21 assertions |
| Laravel tests on PostgreSQL test DB | Passed: 6 tests, 21 assertions; direct PHPUnit returned 0 |
| Foundation migrations on SQLite | Passed; interim scaffold verification |
| Foundation migrations on PostgreSQL | Passed: all four migration files applied |
| PostgreSQL role/database query | Passed; correct database and non-superuser application role |
| GET /api/v1/health over HTTP | Passed: {"status":"ok"}; verified after runtime restart |
| CORS tests | Passed: allowed origin is localhost:4200; hostile origin cannot match that header |
| Cross-family device FK test | Passed: mismatched child/family insertion rejected |
| PHP syntax/Pint | Migration syntax passed; PHP source formatted |
| All PowerShell scripts | Syntax parsing passed; targeted at PowerShell 5.1+ |
| JDK java -version | Passed: 17.0.20.1 |
| gradlew.bat --version | Passed: Gradle 8.13, JVM 17.0.20.1 |
| Android manifest XML parse | Passed; not evidence of an APK build |
| Android assembleDebug/lint/emulator | Not executed: SDK absent |
| Git credential/artifact exclusions | Verified for .env, service config, local properties, vendor, node_modules, .tools, .local |
| git diff --check | No output; source is untracked, so not a comprehensive whitespace check |
| npm audit --omit=dev | Passed: 0 production vulnerabilities |
| Full npm audit | 7 development-only advisories remain (4 moderate, 3 high) in Firebase CLI transitive dependencies |

## Errors investigated and resolved
Initial sandbox npm checks used offline-only mode; task-specific npm network settings and approved escalation resolved package access. Global Angular CLI's sandbox npm discovery failed, so the project-local CLI was used. A duplicate scaffold attempt returned a merge conflict; existing generated source was preserved and initialization continued. An extra Laravel create-project attempt correctly refused its non-empty target. Neither error overwrote existing user work: FamilyGuard did not exist before this task.

CORS library optimizes a single allowed origin by always emitting that fixed origin; the first test expected a missing header and was corrected to test the actual browser-safe behavior. Official PHP first failed because installed VCRUNTIME was old; the approved Microsoft runtime update fixed it. PostgreSQL initialization was attempted before installer libraries were complete; those attempts failed and initdb removed its incomplete cluster. Retrying after libraries were installed succeeded. A PostgreSQL artisan test invocation reported passed tests but returned 1; direct PHPUnit rerun returned 0 with six passing tests. No failed initialization is counted as successful verification.

winget downloads were initially stalled; verified direct vendor copies were downloaded into ignored .tools as a fallback. winget ultimately completed both system installs. The queued duplicate PostgreSQL install after Android correctly reported already installed/no upgrade. A checksum text response from Gradle arrived as bytes; decoding was corrected and both wrapper/distribution checksums verified. One live health request timed out under low memory; the subsequent request succeeded. Existing unrelated projects and their running processes were not changed.

## Remaining manual actions and limitations
1. Android Studio is open at **Select SDKs**. Complete setup and review SDK licenses. Install Android SDK Platform 36, Build Tools 35.0.0 (and 36.0.0), Platform Tools, and Command-line Tools (latest).
2. Open apps/child-android. Set its Gradle JDK to .tools/jdk17/jdk-17.0.20.1+1, not bundled Java 25. Run scripts/build-android.ps1 after SDK setup. Native dependency resolution, compilation and lint remain unverified and may expose issues requiring fixes.
3. No AVD was created. Firmware virtualization is available, but acceleration is untested and current free RAM is very low. Prefer a physical Android device; docs/android/permissions.md covers USB authorization, adb reverse and the required debug URL change.
4. Firebase project registration, providers, fingerprints, SDK files, server credentials and token verification require your account configuration. No paid services were enabled.
5. Seven npm advisories remain in development-only firebase-tools dependencies (braces/chokidar, OpenTelemetry/PubSub and uuid/gaxios). The production audit is clean. A forced CLI downgrade/major override was not applied; revisit upstream fixes before using emulators or deployment. CLI is not loaded by the browser.
6. Backend credentials exist only locally. A fresh clone needs PHP/JDK dependencies, PostgreSQL provisioning and .env setup; scripts do not install system packages or silently run migrations. Full setup-project.ps1 reinstall was not re-executed.
7. API health is a liveness check, not a database or identity readiness endpoint. Browser rendering/accessibility and real Android device behavior have not received interactive QA.

## Startup commands
In PowerShell:
~~~powershell
Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard'
.\scripts\start-database.ps1
.\scripts\start-backend.ps1
# Separate terminal, same project directory:
.\scripts\start-frontend.ps1
# Checks:
.\scripts\run-tests.ps1
# After Android SDK wizard:
.\scripts\build-android.ps1
~~~
Dashboard: http://localhost:4200
API: http://localhost:8000
Health: http://localhost:8000/api/v1/health
Database: 127.0.0.1:5433, familyguard (credentials in ignored .env).
At the final checks, Angular, Laravel and the isolated PostgreSQL cluster were running. Reuse them or stop the corresponding task terminals before starting duplicate servers. The database stop script affects only the FamilyGuard cluster.

Manual artisan commands must use .\.tools\php\php.exe from the monorepo root, for example .\.tools\php\php.exe backend/artisan migrate:status. Normal project scripts select the correct runtime automatically.

## Git and created files
Git initialized at C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard/.git. All project source remains untracked; no commit, staging, remote or global Git identity modification was made. 146 source/config/document files are listed below. Runtime downloads, generated credentials, installed dependencies and build output exist locally but are deliberately excluded from this source inventory.

### Full source directory tree
~~~text
FamilyGuard/
├── .editorconfig
├── .github
│   └── workflows
│       └── verify.yml
├── .gitignore
├── AGENTS.md
├── README.md
├── apps
│   ├── backend
│   │   ├── .editorconfig
│   │   ├── .env.example
│   │   ├── .gitattributes
│   │   ├── .gitignore
│   │   ├── .npmrc
│   │   ├── AGENTS.md
│   │   ├── CLAUDE.md
│   │   ├── README.md
│   │   ├── app
│   │   │   ├── Events
│   │   │   │   └── .gitkeep
│   │   │   ├── Http
│   │   │   │   ├── Controllers
│   │   │   │   │   └── Controller.php
│   │   │   │   ├── Middleware
│   │   │   │   │   └── .gitkeep
│   │   │   │   └── Requests
│   │   │   │       └── .gitkeep
│   │   │   ├── Jobs
│   │   │   │   └── .gitkeep
│   │   │   ├── Models
│   │   │   │   ├── Child.php
│   │   │   │   ├── Device.php
│   │   │   │   ├── Family.php
│   │   │   │   └── User.php
│   │   │   ├── Notifications
│   │   │   │   └── .gitkeep
│   │   │   ├── Policies
│   │   │   │   └── .gitkeep
│   │   │   ├── Providers
│   │   │   │   └── AppServiceProvider.php
│   │   │   └── Services
│   │   │       └── .gitkeep
│   │   ├── artisan
│   │   ├── bootstrap
│   │   │   ├── app.php
│   │   │   ├── cache
│   │   │   │   └── .gitignore
│   │   │   └── providers.php
│   │   ├── composer.json
│   │   ├── composer.lock
│   │   ├── config
│   │   │   ├── app.php
│   │   │   ├── auth.php
│   │   │   ├── cache.php
│   │   │   ├── cors.php
│   │   │   ├── database.php
│   │   │   ├── filesystems.php
│   │   │   ├── logging.php
│   │   │   ├── mail.php
│   │   │   ├── queue.php
│   │   │   ├── services.php
│   │   │   └── session.php
│   │   ├── database
│   │   │   ├── .gitignore
│   │   │   ├── factories
│   │   │   │   └── UserFactory.php
│   │   │   ├── migrations
│   │   │   │   ├── 0001_01_01_000000_create_users_table.php
│   │   │   │   ├── 0001_01_01_000001_create_cache_table.php
│   │   │   │   ├── 0001_01_01_000002_create_jobs_table.php
│   │   │   │   └── 2026_10_15_000001_create_familyguard_tables.php
│   │   │   └── seeders
│   │   │       └── DatabaseSeeder.php
│   │   ├── package.json
│   │   ├── phpunit.xml
│   │   ├── public
│   │   │   ├── .htaccess
│   │   │   ├── favicon.ico
│   │   │   ├── index.php
│   │   │   └── robots.txt
│   │   ├── resources
│   │   │   ├── css
│   │   │   │   └── app.css
│   │   │   ├── js
│   │   │   │   └── app.js
│   │   │   └── views
│   │   │       └── welcome.blade.php
│   │   ├── routes
│   │   │   ├── api.php
│   │   │   ├── console.php
│   │   │   └── web.php
│   │   ├── storage
│   │   │   ├── app
│   │   │   │   ├── .gitignore
│   │   │   │   ├── private
│   │   │   │   │   └── .gitignore
│   │   │   │   └── public
│   │   │   │       └── .gitignore
│   │   │   └── logs
│   │   │       └── .gitignore
│   │   ├── tests
│   │   │   ├── Feature
│   │   │   │   ├── ExampleTest.php
│   │   │   │   ├── FamilyIntegrityTest.php
│   │   │   │   ├── HealthTest.php
│   │   │   │   └── SchemaTest.php
│   │   │   ├── TestCase.php
│   │   │   └── Unit
│   │   │       └── ExampleTest.php
│   │   └── vite.config.js
│   ├── child-android
│   │   ├── .env.example
│   │   ├── .idea
│   │   │   ├── AndroidProjectSystem.xml
│   │   │   ├── caches
│   │   │   │   └── deviceStreaming.xml
│   │   │   ├── child-android.iml
│   │   │   ├── gradle.xml
│   │   │   ├── misc.xml
│   │   │   ├── modules.xml
│   │   │   ├── runConfigurations.xml
│   │   │   ├── vcs.xml
│   │   │   └── workspace.xml
│   │   ├── app
│   │   │   ├── build.gradle.kts
│   │   │   └── src
│   │   │       └── main
│   │   │           ├── AndroidManifest.xml
│   │   │           ├── java
│   │   │           │   └── com
│   │   │           │       └── familyguard
│   │   │           │           └── child
│   │   │           │               ├── MainActivity.kt
│   │   │           │               ├── data
│   │   │           │               │   └── HealthRepository.kt
│   │   │           │               └── ui
│   │   │           │                   └── CompanionViewModel.kt
│   │   │           └── res
│   │   │               └── drawable
│   │   │                   └── ic_familyguard.xml
│   │   ├── build.gradle.kts
│   │   ├── gradle.properties
│   │   ├── gradle
│   │   │   └── wrapper
│   │   │       ├── gradle-wrapper.jar
│   │   │       └── gradle-wrapper.properties
│   │   ├── gradlew
│   │   ├── gradlew.bat
│   │   └── settings.gradle.kts
│   └── parent-dashboard
│       ├── .editorconfig
│       ├── .env.example
│       ├── .gitignore
│       ├── .prettierrc
│       ├── .vscode
│       │   ├── extensions.json
│       │   ├── launch.json
│       │   └── tasks.json
│       ├── README.md
│       ├── angular.json
│       ├── package-lock.json
│       ├── package.json
│       ├── public
│       │   └── favicon.ico
│       ├── src
│       │   ├── app
│       │   │   ├── app.config.ts
│       │   │   ├── app.html
│       │   │   ├── app.routes.ts
│       │   │   ├── app.scss
│       │   │   ├── app.spec.ts
│       │   │   ├── app.ts
│       │   │   ├── core
│       │   │   │   └── health.service.ts
│       │   │   └── features
│       │   │       └── page.ts
│       │   ├── environments
│       │   │   ├── environment.production.ts
│       │   │   └── environment.ts
│       │   ├── index.html
│       │   ├── main.ts
│       │   └── styles.scss
│       ├── tsconfig.app.json
│       ├── tsconfig.json
│       └── tsconfig.spec.json
├── docs
│   ├── android
│   │   └── permissions.md
│   ├── api
│   │   └── endpoints.md
│   ├── architecture
│   │   ├── dependency-decisions.md
│   │   └── overview.md
│   ├── database
│   │   └── schema.md
│   ├── firebase-setup.md
│   ├── implementation-roadmap.md
│   ├── security
│   │   └── architecture.md
│   ├── setup-report.md
│   └── testing
│       └── test-plan.md
├── infrastructure
│   └── local
│       └── README.md
├── packages
│   └── shared-contracts
│       ├── README.md
│       └── health.schema.json
└── scripts
    ├── build-android.ps1
    ├── check-environment.ps1
    ├── common.ps1
    ├── run-tests.ps1
    ├── setup-project.ps1
    ├── start-backend.ps1
    ├── start-database.ps1
    ├── start-frontend.ps1
    └── stop-database.ps1
~~~

### Complete created source file inventory
- .editorconfig
- .github/workflows/verify.yml
- .gitignore
- AGENTS.md
- README.md
- backend/.editorconfig
- backend/.env.example
- backend/.gitattributes
- backend/.gitignore
- backend/.npmrc
- backend/AGENTS.md
- backend/CLAUDE.md
- backend/README.md
- backend/app/Events/.gitkeep
- backend/app/Http/Controllers/Controller.php
- backend/app/Http/Middleware/.gitkeep
- backend/app/Http/Requests/.gitkeep
- backend/app/Jobs/.gitkeep
- backend/app/Models/Child.php
- backend/app/Models/Device.php
- backend/app/Models/Family.php
- backend/app/Models/User.php
- backend/app/Notifications/.gitkeep
- backend/app/Policies/.gitkeep
- backend/app/Providers/AppServiceProvider.php
- backend/app/Services/.gitkeep
- backend/artisan
- backend/bootstrap/app.php
- backend/bootstrap/cache/.gitignore
- backend/bootstrap/providers.php
- backend/composer.json
- backend/composer.lock
- backend/config/app.php
- backend/config/auth.php
- backend/config/cache.php
- backend/config/cors.php
- backend/config/database.php
- backend/config/filesystems.php
- backend/config/logging.php
- backend/config/mail.php
- backend/config/queue.php
- backend/config/services.php
- backend/config/session.php
- backend/database/.gitignore
- backend/database/factories/UserFactory.php
- backend/database/migrations/0001_01_01_000000_create_users_table.php
- backend/database/migrations/0001_01_01_000001_create_cache_table.php
- backend/database/migrations/0001_01_01_000002_create_jobs_table.php
- backend/database/migrations/2026_10_15_000001_create_familyguard_tables.php
- backend/database/seeders/DatabaseSeeder.php
- backend/package.json
- backend/phpunit.xml
- backend/public/.htaccess
- backend/public/favicon.ico
- backend/public/index.php
- backend/public/robots.txt
- backend/resources/css/app.css
- backend/resources/js/app.js
- backend/resources/views/welcome.blade.php
- backend/routes/api.php
- backend/routes/console.php
- backend/routes/web.php
- backend/storage/app/.gitignore
- backend/storage/app/private/.gitignore
- backend/storage/app/public/.gitignore
- backend/storage/logs/.gitignore
- backend/tests/Feature/ExampleTest.php
- backend/tests/Feature/FamilyIntegrityTest.php
- backend/tests/Feature/HealthTest.php
- backend/tests/Feature/SchemaTest.php
- backend/tests/TestCase.php
- backend/tests/Unit/ExampleTest.php
- backend/vite.config.js
- apps/child-android/.env.example
- apps/child-android/.idea/AndroidProjectSystem.xml
- apps/child-android/.idea/caches/deviceStreaming.xml
- apps/child-android/.idea/child-android.iml
- apps/child-android/.idea/gradle.xml
- apps/child-android/.idea/misc.xml
- apps/child-android/.idea/modules.xml
- apps/child-android/.idea/runConfigurations.xml
- apps/child-android/.idea/vcs.xml
- apps/child-android/.idea/workspace.xml
- apps/child-android/app/build.gradle.kts
- apps/child-android/app/src/main/AndroidManifest.xml
- apps/child-android/app/src/main/java/com/familyguard/child/MainActivity.kt
- apps/child-android/app/src/main/java/com/familyguard/child/data/HealthRepository.kt
- apps/child-android/app/src/main/java/com/familyguard/child/ui/CompanionViewModel.kt
- apps/child-android/app/src/main/res/drawable/ic_familyguard.xml
- apps/child-android/build.gradle.kts
- apps/child-android/gradle.properties
- apps/child-android/gradle/wrapper/gradle-wrapper.jar
- apps/child-android/gradle/wrapper/gradle-wrapper.properties
- apps/child-android/gradlew
- apps/child-android/gradlew.bat
- apps/child-android/settings.gradle.kts
- frontend/.editorconfig
- frontend/.env.example
- frontend/.gitignore
- frontend/.prettierrc
- frontend/.vscode/extensions.json
- frontend/.vscode/launch.json
- frontend/.vscode/tasks.json
- frontend/README.md
- frontend/angular.json
- frontend/package-lock.json
- frontend/package.json
- frontend/public/favicon.ico
- frontend/src/app/app.config.ts
- frontend/src/app/app.html
- frontend/src/app/app.routes.ts
- frontend/src/app/app.scss
- frontend/src/app/app.spec.ts
- frontend/src/app/app.ts
- frontend/src/app/core/health.service.ts
- frontend/src/app/features/page.ts
- frontend/src/environments/environment.production.ts
- frontend/src/environments/environment.ts
- frontend/src/index.html
- frontend/src/main.ts
- frontend/src/styles.scss
- frontend/tsconfig.app.json
- frontend/tsconfig.json
- frontend/tsconfig.spec.json
- docs/android/permissions.md
- docs/api/endpoints.md
- docs/architecture/dependency-decisions.md
- docs/architecture/overview.md
- docs/database/schema.md
- docs/firebase-setup.md
- docs/implementation-roadmap.md
- docs/security/architecture.md
- docs/setup-report.md
- docs/testing/test-plan.md
- infrastructure/local/README.md
- packages/shared-contracts/README.md
- packages/shared-contracts/health.schema.json
- scripts/build-android.ps1
- scripts/check-environment.ps1
- scripts/common.ps1
- scripts/run-tests.ps1
- scripts/setup-project.ps1
- scripts/start-backend.ps1
- scripts/start-database.ps1
- scripts/start-frontend.ps1
- scripts/stop-database.ps1

## Next development phase
First complete Android SDK sync/build and address any native build errors. Then implement Firebase identity verification and family authorization policies with cross-family/role denial tests. Secure explicit device pairing follows identity; permission-based reporting and per-session screen sharing remain later phases. See docs/implementation-roadmap.md for acceptance criteria.

Compatibility references: [Angular versions](https://angular.dev/reference/versions), [Laravel releases](https://laravel.com/framework/docs/releases), [AGP 8.13 compatibility](https://developer.android.com/build/releases/agp-8-13-0-release-notes), [official PHP Windows downloads](https://www.php.net/downloads.php?os=windows).
