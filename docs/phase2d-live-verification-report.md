# FamilyGuard Phase 2D — local live verification report

Verified on 2026-10-15, Windows laptop, Asia/Karachi.

## Repository and delivery

- Branch: feature/phase2d-firebase-project-integration.
- Initial Phase 2D commit: a8dc3011b8ad131c92c3b8b309c60ca395358b30. Clean checkout was fetched, switched to the remote tracking branch and pulled with --ff-only. Earlier Phase 2A/2B/2C commits are present in its ancestry.
- Latest verified implementation commit: 3839765f3f5a0a18973c166cf919ed1555480069. Pushed successfully to the same remote branch. This report is a subsequent documentation commit; use git log -1 for its final hash.
- Existing draft PR: https://github.com/hassan324-ll/Parental-monitoring/pull/5 . Base remains feature/phase2c-firebase-parent-guard. No merge into main, reset or force-push.
- No Angular/Laravel recreation, Android changes or new monitoring features.

## Confirmed bugs and corrections

1. The committed public Firebase API key contained lowercase l where the Console value has numeral 1. Google's project configuration endpoint returned HTTP 400 / invalid API key. Applied the exact corrected public web configuration supplied by the owner; the same endpoint now returns HTTP 200.
2. Angular's authentication lifecycle tests were reaching the actual Firebase SDK despite vi.mock module declarations (8 of 28 tests failed, including actual invalid-key errors). Added a typed Angular injection token for the SDK boundary and injected mocks through TestBed. All lifecycle tests now exercise isolated fakes; no security validation was removed.
3. Invalid/empty authentication fields disabled submission without explaining the fields. Added touched-field name/email/password feedback, accessible error descriptions and a regression test. Login still accepts existing provider passwords independently of the stricter registration policy.
4. Backend startup checked global php even though it executes the project-local runtime. It now checks the actual selected executable.
5. Added a tested loopback-only startup/reuse launcher, project-scoped stop script and optional disposable real-provider smoke script. Updated stale status documentation and formatted Phase 2D Angular files.
6. Added an RSA-signed profile → family create/list → child create/list regression on both database engines.

## Configuration

- Angular development Firebase project: family-guard-eb08c; authDomain family-guard-eb08c.firebaseapp.com. All public web config fields match the owner's Console object.
- Ignored apps/backend/.env now has FIREBASE_PROJECT_ID=family-guard-eb08c. Only this setting was changed. Laravel config cache was cleared and services restarted.
- .env is confirmed ignored. No service-account JSON, private key, password, database credential or ID token was staged. Disposable provider credentials exist only in process memory and never print.
- Firebase public project-configuration response confirms localhost is authorized. Newer Firebase projects do not add localhost by default; official guidance: https://firebase.google.com/docs/auth/faq-and-troubleshooting . Console navigation is in the testing guide.
- Production environment remains firebaseConfig: null. Production output was searched and contains zero occurrences of the development project ID. No deployment was performed.
- Local PHP 8.5.11 includes PostgreSQL and SQLite drivers and uses the validated CA bundle. Real Google signing-certificate retrieval returned HTTP 200; five certificates, first parses successfully. TLS verification remains enabled.

## Automated local results

| Check | Actual result |
| --- | --- |
| Laravel PHPUnit / SQLite in-memory | 51 tests, 203 assertions; passed, exit 0 |
| Laravel PHPUnit / separate PostgreSQL familyguard_test | 51 tests, 203 assertions; passed, exit 0 |
| Pint --test | Passed |
| Composer validate --strict | Passed |
| Composer audit --locked | No security advisories |
| Angular Vitest | 29 tests across 7 files; passed, exit 0 |
| Strict TypeScript app and spec checks | Both passed |
| Prettier src TS/HTML/SCSS and tsconfig.json | Passed |
| Angular production build | Passed; initial 395.43 kB, estimated transfer 106.12 kB |
| npm production dependency audit | Zero vulnerabilities |
| npm full development dependency audit | 7 unresolved transitive development-tool advisories: 3 high, 4 moderate; no forced dependency downgrade |
| PowerShell launcher/stop parser checks; Node smoke syntax | Passed |
| git diff --check; staged private-credential/path review | Passed |

Backend tests cover signature/issuer/audience/expiry/email verification rejection, UID mapping and uniqueness races, family membership/role boundaries, foreign keys, indexes, migration lifecycle, pre-auth throttling and the signed parent workflow. Cryptographic API tests use generated in-memory RSA keys and mocked Google certificate responses, not a real verified Google identity. Other API tests use explicit test doubles/actingAs; this is test-only and introduces no application bypass.

The Angular suite covers lifecycle and logout races, token scope/URL traversal boundaries, guards, profile HTTP contract and forms. Real browser persistence still requires a verified provider account.

## PostgreSQL and data safety

- Existing private PostgreSQL 18 cluster is running on 127.0.0.1:5433; system PostgreSQL/default port was not altered.
- All six development migrations are already applied. No pending migration, destructive reset or development data change was required.
- UID unique constraint, relationships, foreign keys and composite constraints are covered by the isolated database suites. Live children/devices family indexes were inspected and present.
- Development aggregate counts remained users=0, families=0, children=0 during inspection. The unverified real-provider account was denied before local identity creation.
- Independently confirmed that the test bootstrap refuses the development database before migrations. Only familyguard_test and SQLite :memory: are reset during tests.

## Actual real Firebase provider test

The initial invalid-key result was reproduced, corrected from owner-provided public Console config, and retested successfully. The saved optional command node scripts/test-firebase-provider.cjs itself was executed successfully with exit 0.

| Real operation | Result |
| --- | --- |
| Project configuration request | HTTP 200; localhost authorized |
| Register disposable synthetic parent | Firebase accepted |
| Update synthetic display name | Accepted |
| Send verification request | Accepted by provider |
| Generate genuine unverified Firebase ID token | Succeeded; never displayed |
| Call live Laravel /api/v1/me with that token | HTTP 401, as required |
| SDK logout | currentUser cleared |
| SDK Email/Password sign-in | Provider accepted; account remains unverified |
| Password-reset request | Accepted by provider |
| Delete disposable account | Succeeded; test accounts cleaned up |

Synthetic addresses used the reserved example.invalid domain. There is no inbox at that domain. Provider request acceptance does not prove email delivery, link completion, verified-parent login, verified-browser persistence, real-provider /me success, or real-provider family API success. These checks await the owner's controlled inbox and the manual guide. No account was marked verified administratively, and no token claim/security check was bypassed.

## Live HTTP and browser results

- Actual launcher started PostgreSQL verification → Laravel → Angular in order; backend/frontend readiness passed. Subsequent launcher runs reused existing listeners, with no duplicate services.
- Backend: http://localhost:8000 ; public health: http://localhost:8000/api/v1/health → HTTP 200, status ok. The root URL is not an API health guarantee.
- Angular/login: http://localhost:4200/auth → HTTP 200, compiled and rendered. Dashboard: http://localhost:4200/dashboard requires verified sign-in.
- Missing credentials on GET /me, GET/POST /families and GET/POST /families/1/children all returned HTTP 401. Invalid bearer /me also returned 401.
- Angular-origin API responses allow http://localhost:4200. Authorization-header CORS preflight returned 204 with authorization allowed.
- Browser login/registration/reset forms rendered. Invalid registration showed name/email/password errors and stayed disabled. Anonymous dashboard navigation redirected to /auth?next=%2Fdashboard.
- Desktop content/page widths matched at 1280px; mobile override 390×844 yielded matching content/page widths of 375px (scrollbar excluded). No page horizontal overflow. Temporary viewport override reset.
- Inspected browser console returned zero warnings/errors. Real browser protected API requests could not be exercised without a verified inbox account; CORS/API behavior was checked separately over HTTP. No claim that every future authenticated network path has been inspected.
- Login tab left open for the owner. Screenshots copied to the chat outputs folder. Services verified healthy after the launcher process exited; background services remain running.

## Changed files

- README.md; docs/firebase-live-integration.md; docs/phase2d-live-testing-guide.md; this report.
- apps/backend/tests/Feature/FirebaseTokenVerifierTest.php.
- apps/parent-dashboard/src/app/core/firebase-auth.service.ts and .spec.ts; core/parent-profile.service.ts (formatting).
- apps/parent-dashboard/src/app/features/auth/auth-page.html and auth-page.behavior.spec.ts; features/page.ts (formatting); src/environments/environment.ts (public key correction).
- scripts/start-backend.ps1; start-familyguard.ps1; stop-familyguard.ps1; test-firebase-provider.cjs.
- Ignored local-only backend .env updated; no unrelated settings changed.

## Remaining problems and manual actions

1. Complete owned-inbox registration → email verification → verified browser login/reload → Verify with Laravel → reset → logout. Then test a second verified synthetic parent for cross-family isolation. The nine-test guide includes action/expected result/failure handling and a local authenticated API example without printing tokens.
2. Family and child create/list exist as authorized APIs, with automated signed-token coverage. There is no family creation UI, pairing, monitoring, GPS, screen capture or sharing implementation in this phase.
3. Immediate revoked-token/disabled-account administrative checks remain a production release gate. Client logout does not invalidate a copied still-valid token; shared certificate-cache locks and deployment hardening also require production design/verification.
4. Seven dev-tool-only dependency advisories remain; production npm and Composer audits are clean.
5. GitHub Actions run 38773042861 for implementation commit 3839765 failed before runner assignment (foundation job: zero steps, blank runner). The owner reports the existing account billing restriction. No billing bypass, workflow workaround, forced rerun or CI pass is claimed. Local results above are the verification evidence.

## Manual features and next milestone

Available: parent auth forms, verification/reset email requests, verified-only dashboard, public API health, dashboard Verify with Laravel, authorized family/child REST APIs and safe local launch/stop commands. See phase2d-live-testing-guide.md for exact PowerShell commands and all nine manual tests.

Next: finish verified-inbox end-to-end acceptance, address remaining auth release gates, then build family-management UI and consent-based Android device pairing as a separate scoped phase. Monitoring capabilities require separate implementation and consent design.
