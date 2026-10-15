# FamilyGuard project verification report

Date: 15 October 2026 (Asia/Karachi).
Project: C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard
Repository: https://github.com/hassan324-ll/Parental-monitoring
Branch: feature/phase2c-firebase-parent-guard
Latest tested application/code commit: 669f3cd8fdb6ee2334a64663e70600b007d9c1b7
Initial synchronized commit: 24bcc1ae8b24549ac82658a6cd4a3fb10d069f9f

## Project status

**Completed and verified locally:** application compilation, the available backend/frontend suites, both database engines, Android lint/signature inspection and the specified security regressions. This does not mean all planned product features or production authentication lifecycle controls are complete.

The initial working tree was clean. Remote branches were fetched; the requested feature branch was checked out and synchronized using --ff-only. Its history contained Phase 2A (ba173bd: family API), Phase 2B (e74711a: parent auth UI) and Phase 2C (24bcc1a: Firebase parent guard). No old-main development, reset, forced push or merge into main was performed. The main branch remained 9f744d7159e25f37bb7493568bdbe2ba92021170 at publication checks.

Working components are the public API health route, protected family/child API foundation, the fail-closed parent authentication interface, and the buildable Android companion shell. Firebase web configuration and FIREBASE_PROJECT_ID remain unset locally. Consequently live protected user flows cannot be certified with actual Firebase accounts in this run. Placeholder reporting, location and monitoring screens remain placeholders.

## Executed checks

| Component/check | Actual result |
|---|---|
| Baseline Laravel suite | 14 tests, 58 assertions passed on SQLite |
| Final PHPUnit, in-memory SQLite | **48 tests, 188 assertions, 0 failures, 0 errors; exit 0** |
| Final PHPUnit, separate PostgreSQL | **48 tests, 188 assertions, 0 failures, 0 errors; exit 0** |
| Laravel wrapper, --without-tty | 48 tests/188 assertions passed; exit 0 |
| Pint | Initial formatting failures corrected; final --test passed |
| Composer validate --strict | Passed |
| Composer audit --locked | No advisories or abandoned packages reported |
| Angular baseline | Production build and 2 existing tests passed |
| Angular final unit suite | **27 tests in 6 files passed** |
| TypeScript application and test projects | Both tsc --noEmit checks passed with strict enabled |
| Angular production build | Passed; final initial bundle approximately 394.87 kB |
| Angular template strictness | strictTemplates enabled; production compilation passed |
| Frontend Prettier | All src TS/HTML/SCSS and tsconfig.json passed final check |
| npm production audit | 0 vulnerabilities |
| Full npm audit | 7 development-only advisories remain: 3 high, 4 moderate |
| Live backend | /api/v1/health: 200 with status ok; families with missing/invalid bearer: 401 |
| Real Google certificate HTTPS fetch through PHP | 200; 4 signing certificates; certificate PEM parsing succeeded after CA repair |
| Live unauthenticated Angular navigation | /dashboard redirected to /auth?next=%2Fdashboard; unconfigured auth shows no form |
| Mobile browser check | Page overflow fixed: content 1,386 px before; page/content both 375 px after; navigation scrolls internally |
| Android assembleDebug | BUILD SUCCESSFUL; 39 tasks up-to-date on the existing unchanged Android sources |
| Android testDebugUnitTest | **NO-SOURCE**; no JVM test cases exist, so this is not a unit-test pass claim |
| Android lintDebug | Initial local PropertyEscape error corrected; fresh --rerun-tasks analysis succeeded, 0 errors / 12 warnings |
| Android APK inspection | APK exists; v2 signature verifies; package/activity/SDK metadata inspected |
| Android runtime smoke | Not executed: ADB has no device and no AVD is configured |
| PowerShell developer scripts | All parsed successfully; this does not claim every startup/setup script was rerun |
| Git review | Staged diff/whitespace, credential/debug-pattern and ignored-file review passed |

The 48 backend tests were executed independently on each database: this is 48 distinct tests, not 96 distinct tests. Early regression failures and fixture/diagnostic failures were investigated and rerun; only final successful runs are counted above. The original tests were retained.

## Backend and authentication findings

1. **Certificate cache lifetime and rotation:** real-signature regressions showed the fixed 30-minute cache ignored shorter Google max-age and denied a new signing key until cache expiry. The verifier now honors max-age minus Age, capped at one hour, respects no-store/no-cache, validates certificate responses, and refreshes unknown keys after a 60-second cooldown. Refresh work is protected by a cache lock. Transport rejects redirects, retains TLS verification and has connection/request timeouts. Expired caches are not used as an authorization fallback.
2. **Temporal claim edge cases:** signed synthetic tokens with a future nbf or auth_time later than iat were previously accepted. These are rejected, as are inconsistent expiry/issue times. Existing RS256, signature, audience, issuer, expiry, UID and boolean verified-email requirements remain enforced. A 60-second future-clock allowance is retained for issue/auth/not-before timestamps; expired tokens are rejected without expiry leeway.
3. **PostgreSQL identity-creation race:** a deterministic uniqueness collision inside an existing transaction reproduced HTTP 500 / SQLSTATE 25P02 in the original resolver. A nested transaction/savepoint now rolls back the failed insert before querying a concurrently mapped UID. Only unique-constraint exceptions receive collision handling; other database errors are not disguised as account collisions. The same regression passed on the fixed implementation. This was a simulated collision, not a parallel-user load benchmark.
4. **Authentication work was not throttled:** simply moving throttle before auth in the route array was insufficient because Laravel middleware priority reordered it. A small explicit pre-auth IP throttle now runs before bearer resolution. Tests confirm 60 invalid requests return 401 and the 61st returns 429. Deployments must configure trusted proxy handling and account for shared-IP traffic.
5. **Database test safety:** application bootstrap now refuses destructive test setup unless the actual connection is in-memory SQLite or a dedicated familyguard_test database name. A separate explicit PostgreSQL PHPUnit configuration was added. A standalone safety check confirmed the development database is rejected before migrations.
6. **Missing query indexes:** PostgreSQL inspection showed children/devices had no leading family_id indexes for family-scoped pagination and counts. A new additive migration creates those indexes and reverses cleanly. Family list queries remain paginated with relationship counts; this is code/index review, not a large-data performance benchmark.
7. **Local PHP trust configuration:** the actual Google public-certificate fetch failed with cURL error 60 despite mocked tests passing. A SHA-256-verified Mozilla bundle from curl's official endpoint was installed only in ignored .tools/php. Its local php.ini now configures curl.cainfo and openssl.cafile. The backend was restarted and real HTTPS returned 200. No verify=false, insecure TLS flag or system trust/security change was used. Fresh clones need equivalent PHP CA configuration; see docs/firebase-setup.md.

Security coverage includes missing/malformed tokens, unsupported algorithm, tampered signatures, expiry, incorrect audience/issuer, UID bounds, unverified/malformed email claims, configuration/network failures, certificate cache expiry/rotation/unknown-key bursts, age/no-store handling, UID-based mapping, refusal to link an unrelated account by email, and signed-token family isolation. Tests generate private signing keys in memory using a public OpenSSL configuration; no private key or Firebase credential is committed.

Cross-family IDs return 404, viewers cannot create children (403), invalid names return 422, and owner/membership creation is atomic. A signed caller cannot choose another owner by supplying owner_id/firebase_uid. Relationships, unique UID/null handling, membership uniqueness, cascade deletion, composite child/family foreign keys, indexes and full rollback/reapply were exercised on both SQLite and PostgreSQL.

### Database preservation

All destructive migration lifecycle tests ran only on :memory: or familyguard_test at the isolated local cluster. The normal development database familyguard on loopback port 5433 was not reset or rolled back. After the test suites passed, only the tested additive firebase_uid and family-index migrations were applied to development. Users/families/children counts were 0 before and 0 afterward; no real seed records were inserted. No passwords or private record contents were printed.

## Frontend findings and verification

- The interceptor's raw prefix test attached credentials to same-origin URLs whose dot segments normalize outside the intended API path. It now compares parsed origins and normalized path boundaries, rejects credentialed/invalid destinations, and supports production-relative API configuration. This finding did not demonstrate token disclosure to an external origin; external destinations are also covered by tests.
- Malformed Firebase public configuration previously crashed service construction; it now disables authentication safely.
- A provider reload failure after successful password sign-in left a partial session. Failed/unverified login now signs out before propagating the error.
- Logout during a pending guard reload could return a stale user. The service rechecks the current user before granting access.
- When email metadata changes from unverified to verified, the guard refreshes the ID token rather than continuing with old email_verified claims.
- Login incorrectly enforced the registration-only eight-character password rule. Login now accepts existing provider passwords; registration retains the stronger policy. Whitespace-only names are rejected, entered emails are trimmed, passwords are cleared on mode changes, duplicate submissions are blocked, and mode buttons are disabled while a request is active. Reset responses remain generic.
- The source had lost strict TypeScript/template settings; both are enabled and compilation passes. Formatter drift was corrected without changing application architecture.
- A real mobile-browser check exposed grid/sidebar intrinsic-width overflow. The sidebar/grid are constrained so navigation scrolls within the viewport and the sign-in card remains visible. Browser evidence was saved in chat outputs.

Guards, token destinations, form behavior and mocked SDK registration/verification/reset/logout/session races are covered by automated tests. Real email delivery, sign-in against an actual project and production API routing behind a reverse proxy remain untested because Firebase configuration/account access is absent. The Angular app does not automatically grant access when Firebase is unconfigured.

## Android verification

JDK 17.0.20.1, Gradle 8.13, AGP 8.13.2 and the existing Kotlin 2.2.21 configuration were retained. SDK Platform 36, Build Tools 35.0.0 and ADB 37.0.1 are installed locally.

APK: C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\apps\child-android\app\build\outputs\apk\debug\app-debug.apk
Size: 15,165,989 bytes.
SHA-256: 1D8F30AE76A5043183B32FD83A98697120756D34B772DFF47644EE08E127CC09
Package: com.familyguard.child.debug
Launcher: com.familyguard.child.MainActivity
Minimum SDK: 26; target/compile SDK: 36.

Lint found an unescaped Windows drive colon in ignored local.properties. It was corrected to the C\:/... properties form; because Gradle had reused its old report, a fresh --rerun-tasks lint analysis was required and passed. No lint baseline or check suppression was added.

The 12 remaining lint warnings concern dependency version availability, modern Android data-extraction/backup rules and a KTX style suggestion. Existing SDK XML-version/deprecated Gradle DSL warnings are nonblocking. Dependency versions were not broadly upgraded simply because newer versions exist. Data-extraction rules and device credential storage should be hardened before pairing stores sensitive state.

The source manifest declares INTERNET. Inspection of the final merged APK also found existing library permissions for network state, notifications, wake locks, FCM, boot rescheduling and foreground services. No new monitoring permissions or runtime permission prompts were introduced by this verification. No location, usage access, message access or capture implementation was added.

No phone/emulator is connected, so startup, Compose navigation and networking have not been device-tested. The debug API URL remains the emulator host mapping 10.0.2.2:8000; it must be configured appropriately for a physical phone. The existing app run configuration remains available. See apps/child-android/ANDROID-BUILD-RESULT.md for launch instructions, while this report supersedes its older unexecuted-lint status.

## GitHub publication

Code corrections commit: [669f3cd](https://github.com/hassan324-ll/Parental-monitoring/commit/669f3cd8fdb6ee2334a64663e70600b007d9c1b7). It was pushed normally to feature/phase2c-firebase-parent-guard and the working tree was clean immediately afterward. This report is published in a subsequent documentation-only commit; its final publication SHA is given in the conversation/PR history rather than a self-referential hash in this file.

[PR #3](https://github.com/hassan324-ll/Parental-monitoring/pull/3) is open and remains draft. Its head is the requested Phase 2C branch and its base remains feature/phase2b-parent-auth-shell. The existing PR title/body were updated with the fixes, actual local results and release limits. No duplicate PR was created and no merge was performed.

GitHub Actions remains unavailable because of the account billing restriction documented by the user/repository. [Run 38721389234](https://github.com/hassan324-ll/Parental-monitoring/actions/runs/38721389234), on the verified code commit, failed with no runner and zero executed job steps. That is not a hosted test result. No workflow changes, weakened checks or billing bypass were attempted. The account owner must resolve the restriction before hosted CI can validate this branch.

### Files changed by the code corrections commit

- apps/backend/AGENTS.md
- apps/backend/app/Auth/FirebaseParentUserResolver.php
- apps/backend/app/Auth/FirebaseTokenVerifier.php
- apps/backend/app/Http/Controllers/Api/FamilyController.php
- apps/backend/app/Http/Middleware/ThrottleParentAuthentication.php
- apps/backend/bootstrap/app.php
- apps/backend/database/migrations/2026_10_15_000003_index_family_children_and_devices.php
- apps/backend/phpunit.postgres.xml
- apps/backend/routes/api.php
- apps/backend/tests/Feature/FirebaseParentAuthTest.php
- apps/backend/tests/Feature/FirebaseTokenVerifierTest.php
- apps/backend/tests/Feature/MigrationLifecycleTest.php
- apps/backend/tests/Fixtures/openssl.cnf
- apps/backend/tests/TestCase.php
- apps/parent-dashboard/src/app/app.scss
- apps/parent-dashboard/src/app/core/auth-token.interceptor.spec.ts
- apps/parent-dashboard/src/app/core/auth-token.interceptor.ts
- apps/parent-dashboard/src/app/core/firebase-auth.service.spec.ts
- apps/parent-dashboard/src/app/core/firebase-auth.service.ts
- apps/parent-dashboard/src/app/core/parent-auth.guard.spec.ts
- apps/parent-dashboard/src/app/features/auth/auth-page.behavior.spec.ts
- apps/parent-dashboard/src/app/features/auth/auth-page.html
- apps/parent-dashboard/src/app/features/auth/auth-page.scss
- apps/parent-dashboard/src/app/features/auth/auth-page.ts
- apps/parent-dashboard/src/app/features/page.ts
- apps/parent-dashboard/tsconfig.json
- docs/android/permissions.md
- docs/api/endpoints.md
- docs/database/schema.md
- docs/firebase-setup.md
- docs/testing/test-plan.md

This report adds docs/verification-report.md. Local-only repairs to ignored local.properties, .tools/php/php.ini and its public CA bundle were not committed. Credentials, local database files, dependencies and generated APKs/lint output remain ignored. Staged credential/debug-pattern review passed; temporary diagnostic logging was removed. No global development software or security settings were changed.

## Remaining issues and release gates

1. Configure the real Firebase web application and backend project ID, enable the provider, and exercise registration, email verification, reset, logout and protected API calls with explicitly synthetic provider accounts. Do not add a local authentication bypass.
2. Implement/check immediate revoked-session and disabled-user handling through an appropriately configured server identity provider/admin integration before production. Current signature/claim verification alone can accept a previously issued token until expiry; client logout does not invalidate copied bearer tokens.
3. Configure a shared lock-capable cache for multi-host deployment and perform actual multi-worker/network-failure/load tests. Local deterministic cache and SQL race tests do not prove distributed behavior. Signing-key refresh cooldown may temporarily deny a newly introduced key for up to 60 seconds; failures are intentionally fail-closed.
4. Seven development-only Firebase CLI advisories remain (braces/chokidar, OpenTelemetry/PubSub, uuid/gaxios and their direct CLI aggregation). No production npm vulnerability or Composer advisory was reported. Resolve through compatible upstream releases before relying on these CLI services; no forced major override/downgrade was made.
5. Resolve GitHub billing restriction, then run hosted CI normally.
6. Connect an authorized phone or configure an emulator, then smoke-test Android startup, navigation, settings and health networking. Android JVM tests currently have no test sources. Review remaining backup/dependency lint warnings before production device credentials exist.
7. Pairing, independently revocable device credentials, real reporting/locations, retention jobs, approved screen sharing and production deployment are incomplete. No compliance review, external penetration test or production readiness certification is claimed.

## Next milestone

Complete configured-provider identity acceptance and server session lifecycle checks first: verified signup/login, explicit legacy-account collision recovery, disabled/revoked identity denial, and end-to-end family policy tests. In parallel, perform the first authorized Android device smoke test. After these gates pass, implement explicit short-lived one-use device pairing with consent and independently revocable credentials. Monitoring features remain later phases.

## Reproduce local checks

~~~powershell
Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\apps\backend'
& '..\..\.tools\php\php.exe' vendor/phpunit/phpunit/phpunit
& '..\..\.tools\php\php.exe' vendor/phpunit/phpunit/phpunit --configuration=phpunit.postgres.xml
& '..\..\.tools\php\php.exe' vendor/bin/pint --test
composer validate --strict
composer audit --locked

Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\apps\parent-dashboard'
npm.cmd test -- --watch=false
npm.cmd run build
npm.cmd exec tsc -- --noEmit -p tsconfig.app.json
npm.cmd exec tsc -- --noEmit -p tsconfig.spec.json
npm.cmd exec prettier -- --check 'src/**/*.{ts,html,scss}' tsconfig.json
npm.cmd audit --omit=dev

Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\apps\child-android'
$env:JAVA_HOME='C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\.tools\jdk17\jdk-17.0.20.1+1'
.\gradlew.bat assembleDebug testDebugUnitTest lintDebug --max-workers=2
~~~

The dedicated PostgreSQL database must exist and the development .env connection credentials must be set locally; phpunit.postgres.xml forces the test database and clears DB_URL. Do not substitute the development database name.

Security references consulted: [Firebase ID token verification](https://firebase.google.com/docs/auth/admin/verify-id-tokens), [Firebase session management](https://firebase.google.com/docs/auth/admin/manage-sessions), [curl TLS trust](https://curl.se/docs/sslcerts.html).
