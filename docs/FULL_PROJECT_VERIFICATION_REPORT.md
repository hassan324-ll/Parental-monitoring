# FamilyGuard — Full Project Verification Report

Verified 18 October 2026 (Asia/Karachi). This replaces the earlier Phase 2D-only, permission-blocked assessment.

## A. Environment

- Windows 10 amd64; PowerShell. Project: `C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard`.
- Node 24.21.0, npm 11.19.0; Angular 22.2, TypeScript 6.0, Vitest 5.0.4.
- Project PHP `.tools/php/php.exe`: 8.5.11; Composer 2.8.12. Default Herd PHP 8.5.0 is not used for database tests.
- Temurin JDK 17.0.20.1, Gradle 8.13, AGP 8.13.2, Kotlin 2.2.21. JDK 17 matches the project targets; no JDK replacement or global ACL change was required.
- SDK: `%LOCALAPPDATA%\Android\Sdk`, Platform 36, Build Tools 35.0.0. No emulator/device connected.
- PostgreSQL 18.6. Started the existing FamilyGuard-only cluster on loopback port 5433; tests used **familyguard_test**, never the development database. The separate service on 5432 was not modified.
- Existing lockfile dependencies were present. Composer install dry-run reported nothing to install/update/remove. Added only JUnit 4.13.2 and kotlinx-coroutines-test 1.10.2 as Android test dependencies for regression coverage; Gradle resolved them and executed the tests.
- Initial Angular `spawn EPERM` and Gradle lock access errors were execution-sandbox restrictions. Authorized command execution resolved them; no blanket file permission change, security bypass or cache deletion was used. Approval-service timeouts remained intermittent and are distinct from application failures.

## B. Git and phase lineage

- Clean starting branch: `feature/phase2d-firebase-project-integration`, `772b3e80667f89e44f15dcaab66edf128db410e5`.
- Fetched all remote branches and safely switched the clean checkout to tracking **phase3b-pairing-ui-plan**.
- Audited starting head: `6e82220d2f3a6119c8eca60ec96b56bffcbb9f2f`.
- Phase 2E: `5452b8c`, based on Phase 2D structure commit `57bd978`; Phase 3A: `294ff0d`, based on Phase 2E; Phase 3B adds the Connected Devices interface, routing, Android API client and disabled-approval preview UI through `6e82220`.
- The Phase 3B branch does **not** include the later Phase 2D commits `7a264e8` (login latency optimization) and `772b3e8` (Angular CLI preference). Those commits remain preserved on the original branch. They were reviewed, not silently merged or cherry-picked into this work.
- No reset, force push, merge into main, deletion of existing work or production deployment. Main's observed commit remains `9f744d7159e25f37bb7493568bdbe2ba92021170`.
- Verified implementation and report commits: see publication addendum below and `git log`.

## C. Backend results

| Check | Baseline | Final |
| --- | --- | --- |
| SQLite suite | 55 passed / 240 assertions | **59 total: 58 passed, 1 PostgreSQL-only test skipped; 276 assertions; exit 0** |
| PostgreSQL familyguard_test suite | 55 passed / 240 assertions | **59 passed / 281 assertions; exit 0** |
| Two-worker PostgreSQL approval race | Not previously covered | **1 test / 5 assertions passed** |
| Composer validation | Passed | Passed |
| Composer locked dependency audit | No advisories or abandoned packages | Passed in this audit |
| Laravel Pint | Failed on four Phase 3A files initially | Full formatter applied; final `--test` passed |
| Migration lifecycle | Included in passing suites | Fresh schema, rollback/reapply, constraints, cascade and family indexes exercised on both isolated engines |

Exact commands, run from `backend/`:

```powershell
& '..\.tools\php\php.exe' vendor/bin/phpunit --do-not-cache-result
$env:DB_CONNECTION='pgsql'
$env:DB_DATABASE='familyguard_test'
$env:DB_HOST='127.0.0.1'
$env:DB_PORT='5433'
& '..\.tools\php\php.exe' vendor/bin/phpunit --do-not-cache-result
& '..\.tools\php\php.exe' vendor/bin/phpunit --do-not-cache-result --filter=PairingConcurrencyTest
composer validate --strict --no-check-publish
composer install --dry-run --no-interaction --no-scripts
composer audit --locked --format=json --no-interaction
& '..\.tools\php\php.exe' vendor/bin/pint
& '..\.tools\php\php.exe' vendor/bin/pint --test
```

SQLite commands run in a separate process without the PostgreSQL overrides. The existing test bootstrap refuses destructive tests unless SQLite is in-memory or the PostgreSQL name matches the reserved familyguard_test pattern. Production/development migrations were not run. The concurrency test uses DatabaseMigrations on that isolated test database, starts two PHP workers, synchronizes their approval requests with a temporary-file barrier, and verifies exactly one 201, one 404, one device and a consumed invitation. Workers never print invitation codes, credentials or database passwords.

Authentication coverage includes RSA signature/issuer/audience/expiry and verified-email checks, malformed/expired tokens, certificate rotation/cache/failure handling, UID mapping, collision races and pre-auth rate limiting. Family tests cover creation/listing, validation, viewer roles and cross-family isolation.

Pairing coverage includes parent authentication, child membership, preview, explicit approval validation, exact expiry boundary, lower-case code normalization, one-time use/replay rejection, credential hashing, device/parent credential separation, listing without hashes or tokens, owner revocation, self-disconnection, post-revocation credential rejection, outside-family denial, read-only viewer restrictions, rate limiting and actual concurrent approval.

No JSON boolean is treated as proof of physical consent: the API can validate only a submitted approval value and possession of an invitation. Documentation and the Android approval gate continue to make this limitation explicit. Bearer invitations must remain private; deployment requires HTTPS. Immediate Firebase refresh-token revocation/disabled-user checks remain an existing production gate, not implemented by this audit.

## D. Angular results

- Initial suite: **35 passed, 2 failed** (37 tests). The family tests asserted before asynchronous initialization completed; `fixture.whenStable()` alone did not wait for the unmanaged promise chain. Tests now wait for the observable loading state to settle before asserting; no sleeps or weakening of assertions.
- Final suite: **41 passed across 10 files**, exit 0. New Connected Devices tests cover pagination HTTP parameters, stale invitation responses after changing away/back to a family, expired-code hiding, revoke confirmation and refresh.
- Strict application and spec TypeScript checks passed. Prettier initially reported 12 files; final source/config check passed after formatting.
- Production build baseline passed (399.83 kB initial, 107.33 kB estimated transfer). Final post-fix production rerun result is recorded in the publication addendum; no baseline pass is substituted for that rerun.
- Full npm audit reports **7 development-tool vulnerabilities: 3 high, 4 moderate**, in Firebase CLI transitive dependencies. No forced downgrade or broad dependency rewrite was applied. Production-only audit result is recorded in the addendum.
- No lint script/target exists; TypeScript, Angular compilation and Prettier are the available checks.

Commands from `frontend/`:

```powershell
npm.cmd test -- --watch=false
npm.cmd run build
node node_modules/typescript/bin/tsc --noEmit -p tsconfig.app.json
node node_modules/typescript/bin/tsc --noEmit -p tsconfig.spec.json
node node_modules/prettier/bin/prettier.cjs --check 'src/**/*.{ts,html,scss}' tsconfig.json
node node_modules/prettier/bin/prettier.cjs --write 'src/**/*.{ts,html,scss}' tsconfig.json
npm.cmd audit --json
npm.cmd audit --omit=dev --json
```

Confirmed UI fixes: Connected Devices previously fetched only the first page of families, children and devices; explicit pagination now makes later pages accessible. A response from an old family selection could display an invitation after switching away and back; request generations now discard it. Expired invitation codes are hidden with an expiry explanation. Rate-limit errors receive explicit feedback. Existing confirmations and server authorization remain in place.

Browser responsive/accessibility and real verified-parent interactive acceptance remain pending: browser automation hit an approval-review timeout. Component/template and HTTP tests ran, but are not represented as a completed browser acceptance test.

## E. Android results

- Baseline debug build and lint passed; baseline JVM task was NO-SOURCE.
- After fixes: **assembleDebug, lintDebug and testDebugUnitTest passed**. Three JVM tests executed, zero failures/errors/skips: cancellation during preview, code change during preview and secure-storage approval gate.
- An intermediate compile failure in the new test seam (missing Kotlin `override`) was corrected before the successful final run.
- Current debug APK: `apps/child-android/app/build/outputs/apk/debug/app-debug.apk`, **15,187,007 bytes**, generated 18 October 2026. `apksigner verify --verbose` passed, v2 signature valid.
- Lint: no errors; 13 warnings remain. SDK XML version mismatch and the existing deprecated kotlinOptions DSL were reported. No unverified toolchain migration was introduced.
- `adb devices -l` shows no device. Emulator install/launch, live preview UI, orientation/accessibility and physical cancellation interaction remain **Pending Device Verification**.

Commands from project root:

```powershell
. ./scripts/common.ps1
Set-Location apps/child-android
./gradlew.bat --version
./gradlew.bat assembleDebug lintDebug testDebugUnitTest --no-daemon
# from root, after common.ps1:
& "$env:ANDROID_HOME/build-tools/35.0.0/apksigner.bat" verify --verbose apps/child-android/app/build/outputs/apk/debug/app-debug.apk
& "$env:ANDROID_HOME/platform-tools/adb.exe" devices -l
```

MainActivity, CompanionViewModel, PairingViewModel and PairingRepository were inspected. Cancel is now available while a preview request is in flight, cancels/invalidate the request and cannot restore stale family/child disclosure afterward. The API client no longer follows redirects containing pairing POST data. New tests use a preview-only injectable interface; they cannot issue approval.

**DeviceCredentialStore.kt is absent.** No secure persistent credential storage, restart restoration or working disconnect UI exists. Approval remains disabled in the visible UI and ViewModel; it must not be enabled just to make a demo complete. Pairing success is never claimed. The manifest requests INTERNET only; no monitoring, private-message interception, hidden permission grant or screen capture was added.

## F. Live integration

Started Laravel on 127.0.0.1:8000 explicitly with APP_ENV=testing, in-memory SQLite, array cache/session; no development database writes. Started Angular on localhost:4200.

| Live check | Actual result |
| --- | --- |
| GET /api/v1/health | 200 |
| Anonymous GET /api/v1/families | 401 |
| Anonymous GET /api/v1/me | 401 |
| OPTIONS preflight from localhost:4200 | 204; correct allowed origin |
| Firebase project configuration | HTTP 200; localhost authorized |
| Disposable synthetic account registration/sign-in | Provider accepted |
| Verification/reset requests | Provider accepted; no inbox delivery claim |
| Genuine unverified Firebase ID token to Laravel /me | 401 |
| Logout | Firebase current user cleared |
| Disposable account cleanup | Deleted successfully |

Provider command: `node scripts/test-firebase-provider.cjs`. It creates an in-memory-auth synthetic account with a reserved invalid-domain address, does not log credentials or tokens, and deletes it afterward. Successful verified-parent browser integration and inbox verification remain **Pending Manual Verification**. No real child data was used. Authenticated family/pairing paths were exercised with synthetic identities in isolated backend HTTP tests, not falsely described as a full browser/device end-to-end run.

## GitHub PRs and CI

- PR #5 Phase 2D, PR #6 Phase 2E and PR #7 Phase 3A are open drafts; returned metadata reports mergeable, without requested reviewers. None was merged or made ready.
- Phase 3B starting head has no PR-triggered workflow runs returned by the connector. That endpoint filters to PR runs and is not proof that no push runs exist.
- Phase 3A run **38777740519**: GitHub reports completed/failure; job **116569665713** foundation reports failure and no step/log details. The PR describes billing restrictions, but this run's exact billing cause was not independently established. No CI pass is claimed and no executed test failure is inferred from missing step details.
- The existing workflow runs Angular and Laravel SQLite, but lacks Android and a real PostgreSQL matrix/service. This audit does not replace that missing CI coverage with claims of production readiness.

## G. Fixes and regression coverage

1. Correct asynchronous family test setup; retain family/child creation assertions.
2. Connected Devices pagination, stale invitation suppression, expiry handling and rate-limit feedback; four component regressions.
3. Android cancel/input-change preview race fix, visible cancel during loading, secure-storage gate preserved; three JVM regressions. Reject HTTP redirect forwarding of pairing data.
4. Backend coverage expanded for authorization, expiry, token secrecy, rate limiting and PostgreSQL concurrent approval; existing runtime pairing behavior remained intact.
5. Format the newer Angular/PHP files; ignore generated Android `.kotlin/` cache. No secrets or local settings are staged.

Files include the devices component/template/spec, family component spec, Android pairing ViewModel/repository/MainActivity/test dependencies/tests, DevicePairingTest, PairingConcurrencyTest and its worker, plus formatter-only changes in the newer files. See the commit diff for the complete file list.

## H. Remaining blockers and risks

| Severity | Finding |
| --- | --- |
| High | Keystore-backed credential storage absent: approval, persistent pairing and disconnect/restart workflow must remain gated |
| High | No real verified-parent inbox/device end-to-end acceptance; do not release completed pairing |
| Medium | Seven transitive development-only Firebase CLI audit findings; production-only result recorded below |
| Medium | GitHub CI failed/insufficient evidence, lacks PostgreSQL and Android verification |
| Medium | API boolean is not attested physical consent; visible UI and future secure storage/device validation remain release gates |
| Medium | No emulator/device connected; runtime Android behavior remains pending |
| Medium | Firebase immediate revoked-session/disabled-account checks remain production work |
| Low | 13 Android lint warnings, SDK command-line XML mismatch and deprecated Kotlin compiler DSL |
| Low | Branch lacks later Phase 2D login optimization/CLI preference; preserved for deliberate integration, not overwritten |
| Low | Intermittent tool approval timeouts can block browser/final commands; report exact outcomes in addendum |

No new Critical vulnerability was confirmed; this is not blanket security certification.

## I. Phase-by-phase readiness

| Phase | Result and next gate |
| --- | --- |
| 2D authentication | Synthetic security tests and genuine unverified-token integration pass; verified inbox/browser persistence acceptance pending |
| 2E family UI | Build/types and corrected family tests pass; real verified-parent browser acceptance pending |
| 3A pairing API | SQLite/PostgreSQL migrations and pairing security tests pass, including two-worker replay race; CI/review/physical-consent limitation remain |
| 3B preview/UI | Component and Android JVM tests pass; Android debug/lint pass. Preview stabilization is ready to continue; **complete pairing is not release-ready** without secure storage and real device verification |

Do not begin Phase 3C or add monitoring. The next permitted tasks are bounded Phase 3B stabilization and acceptance preparation. Implementing/enabling credential storage and approval requires its own explicit implementation decision; it is not smuggled into a testing fix.

The user requested a continuing planning/execution loop with the pinned ChatGPT chat **FamilyGuard Daily Development Report** (6ad21f97-3a84-83ec-ac3e-4a5a44fbdcac). Cross-chat reporting is authorized. Scheduling status will be reported from the actual automation result, not presumed. Stop instructions and the no-main/no-Phase-3C scope continue to control every iteration.

## Publication addendum

Final production build, production dependency audit, implementation commit, report commit and recurring follow-up status are appended after those operations complete. Anything not recorded as passed here remains unverified.

- Final strict app/spec TypeScript and Prettier checks passed; production npm audit returned zero vulnerabilities.
- Final production build rerun was blocked: restricted execution returned spawn EPERM, and both authorized-execution retries timed out in automatic approval review. The baseline production build passed, and post-fix Angular tests compiled the templates, but a post-fix production bundle is not claimed.
- Recurring 30-minute heartbeat creation was attempted twice; both returned approval-review timeout errors. No active automation is confirmed. The bounded continuation prompt will be posted to the authorized pinned report chat; autonomous scheduled continuation cannot be claimed until scheduling succeeds.

### Final confirmed outcomes (supersedes earlier pending entries)
- Post-fix Angular production build passed, exit 0: initial bundle 399.83 kB, estimated transfer 107.26 kB; final generated device-management lazy chunk 9.30 kB. The intermittent permission failures were resolved for this command without modifying system permissions.
- Implementation/report commit 12c2467 was pushed to phase3b-pairing-ui-plan. No main merge or force push.
- Posted the verified status and bounded continuation prompt to the pinned FamilyGuard Daily Development Report chat.
- Recurring heartbeat successfully created after correcting its target-thread argument: automation familyguard-development-verification-loop, ACTIVE, every 30 minutes, attached to this Codex chat. Earlier timeout/invalid-argument attempts did not create an active automation. Every iteration remains confined to authorized Phase 3B stabilization and must honor human stop instructions.
- Browser/device/inbox acceptance and missing secure storage remain pending; a successful production build does not resolve those gates.

## Scheduled follow-up — 18 October 2026: stale revocation failure

- Started from clean, synchronized `phase3b-pairing-ui-plan` at `292019b`; fetch reported zero ahead/behind. No newer human instruction expanded the Phase 3B scope.
- Confirmed bug: a device revocation that fails after switching families displayed the old request's error on the newly selected family. The existing success and invitation paths already used request generations; the revocation catch did not.
- Added a failing regression first. Targeted run: 4 passed, 1 failed; the stale error was reproduced as `Request failed. Please try again.` on family 2.
- Fix: apply the existing request-generation comparison in the revocation catch. Add a second test proving current-family errors remain visible and busy state clears.
- Files changed: `frontend/src/app/features/devices/device-management.ts` and its `.spec.ts`, plus this report. No backend, Android, approval, credential-storage or monitoring changes.
- Verification: `npm.cmd test -- --watch=false`: **43 tests passed across 10 files**, exit 0. This supersedes the earlier 41-test frontend total.
- `npm.cmd run build`: passed, initial 399.83 kB, estimated transfer 107.26 kB. Both `tsc --noEmit -p tsconfig.app.json` and `tsc --noEmit -p tsconfig.spec.json` passed. Prettier check passed for the two changed source files. `git diff --check` passed.
- Previously verified database/Android results are historical evidence, not newly rerun checks: no unchanged backend or Android suite was repeated for this frontend-only correction.
- Secure storage, real verified-parent/device/browser acceptance and CI blockers remain unchanged. No merge to main or Phase 3C work. The verified commit is recorded in Git history and the authorized pinned report-chat handoff.

## Scheduled follow-up — 18 October 2026: family-page loading lifecycle

- Started clean at `ce7016c`; fetched remote branch remained synchronized (zero ahead/behind). Read the pinned report chat and retained the existing Phase 3B-only scope.
- Confirmed three failures before implementation changes: an older family load cleared loading while a newer selection was pending; out-of-order list responses replaced the latest family page; a response after component destruction started new child/device requests. Targeted reproduction: **6 passed, 3 failed**.
- Minimal correction: family-page loads now capture/increment the existing request generation, ignore stale responses/errors and clear loading only while they own the current generation. Child/device selection continues to own its nested loading completion. Component destruction already invalidates the same generation. Removed the redundant increment in the empty-page path.
- Added five regressions covering the three reproduced races plus current-page empty and failure controls. Current errors still display, and current empty pages clear the selection and loading state.
- `npm.cmd test -- --watch=false`: **48 passed across 10 files**, exit 0; supersedes the earlier 43-test frontend total.
- `npm.cmd run build`: passed, initial 399.83 kB / estimated transfer 107.25 kB; Connected Devices lazy chunk 9.39 kB. App/spec `tsc --noEmit` checks, changed-file Prettier and `git diff --check` passed.
- Scope: Connected Devices component/spec and this report only. Backend/Android/database suites were not rerun for an unchanged area. No new monitoring, pairing approval, credential storage, Phase 3C or main changes.
- Existing secure-storage, real verified-parent/browser/device and CI gates remain unresolved; this fix does not change release readiness. The verified commit is recorded in Git history and the authorized pinned report-chat handoff.

## Scheduled follow-up — 18 October 2026: revocation refresh completion

- Started clean at `c94e7ba`; fetched development branch was synchronized (zero ahead/behind). Read the latest pinned report-chat context; no scope expansion or stop instruction.
- Reproduced two stale success paths: after successful API revocation, the list refresh can finish after a family switch or component destruction, and the outer revocation method then displayed `Device access revoked.` despite losing ownership of the current UI. Targeted baseline: **11 passed, 2 failed**.
- Minimal fix: capture the generation assigned by the refresh and check it again after awaiting that refresh before displaying the success notice. Server revocation behavior is unchanged; only stale presentation is suppressed. The existing current-family success/confirmation test remains intact.
- Added two regressions for family switching and destruction during refresh. `npm.cmd test -- --watch=false`: **50 passed across 10 files**, exit 0, superseding the earlier 48-test total.
- `npm.cmd run build` passed (399.83 kB initial, estimated transfer 107.27 kB; device-management lazy chunk 9.44 kB). App/spec `tsc --noEmit`, changed-file Prettier and `git diff --check` passed.
- Changed only the Connected Devices component/spec and this report. No repeated unchanged backend/Android/database suites, no new features, no monitoring, no enabled approval, no Phase 3C and no merge to main.
- Secure credential storage, real verified-parent/browser/device/inbox acceptance and CI gaps remain unchanged release gates. The verified commit is available in Git history and the authorized pinned report-chat handoff.

## Manual loop continuation — 18 October 2026: family form context and teardown

- Started clean at `5b6b945`; fetched development branch remained synchronized (zero ahead/behind). Continued existing Phase 2E/3B stabilization only; no new features or expanded authority from the report chat.
- Reproduced three family-form failures before fixes: old child-creation success erased a draft for another family and showed stale status; a failure after switching away/back displayed an obsolete error; a completion after page destruction started another child request. Targeted baseline: **4 passed, 3 failed**.
- Fix: track the selected-family generation for child submissions and invalidate it on selection changes, empty selection and teardown. Ignore stale success/error callbacks. Guard family/child loading and family creation against starting or chaining requests after teardown; existing child-load request generation is invalidated too. Requests already submitted to the backend are not undone or represented as cancelled mutations.
- Added six regressions: three reproduced failures, current-family error/draft preservation, family-list completion after teardown and family-creation completion after teardown. Existing current-family creation tests remain intact.
- Final frontend result: **56 passed across 10 files**, exit 0, superseding the earlier 50-test total. Intermediate suite after the first three new regressions passed 53 tests; the final six-regression result is 56.
- `npm.cmd run build` passed (399.83 kB initial, estimated transfer 107.25 kB; family-management lazy chunk 13.54 kB). App/spec `tsc --noEmit`, changed-file Prettier and `git diff --check` passed.
- Files changed: family-management component/spec and this report only. No backend/Android/database changes or repeated unchanged suites; no main merge, Phase 3C, monitoring features or enabled pairing approval.
- Secure credential storage, real verified-parent/browser/device/inbox acceptance and CI limitations remain unchanged. The verified commit is recorded in Git history and the authorized pinned report-chat handoff.

## Scheduled follow-up — 18 October 2026: preserve edits during creation

- Started clean at `df1a1f0`; `git fetch origin` and `git rev-list --left-right --count HEAD...origin/phase3b-pairing-ui-plan` confirmed zero ahead/behind. Read the latest pinned report chat; no new human scope changes.
- Confirmed two data-loss cases in the editable family/child forms: type a new draft while an earlier creation request is pending, then the success response unconditionally resets that new draft. Regression-only baseline command `npm.cmd --prefix frontend test -- --watch=false --include=src/app/features/family/family-management.spec.ts` produced **10 passed, 2 failed**, exit 1, both receiving an empty value instead of the new draft.
- Fix: capture the submitted raw name and reset only when the current control still matches it. API payloads remain trimmed; successful mutations still refresh lists and show confirmation. Existing selection/teardown guards remain in place. Added two pending-response regressions and assertions that unchanged successful submissions still reset normally.
- From `frontend`, `npm.cmd test -- --watch=false`: **58 passed across 10 files**, exit 0, superseding 56. `npm.cmd run build`: passed, initial 399.83 kB / estimated transfer 107.29 kB; family-management lazy chunk 13.64 kB.
- From `frontend`, `node node_modules/typescript/bin/tsc --noEmit -p tsconfig.app.json`, `node node_modules/typescript/bin/tsc --noEmit -p tsconfig.spec.json`, and `node node_modules/prettier/bin/prettier.cjs --check src/app/features/family/family-management.ts src/app/features/family/family-management.spec.ts` passed. Root `git diff --check` passed.
- Scope: family-management component/spec and report only. No backend, Android or database changes; their unchanged suites were not rerun. No main merge, force push, production settings, Phase 3C, monitoring or enabled unfinished pairing approval.
- Phase 3B remains suitable for continued bounded stabilization, not release sign-off: missing secure credential storage, real verified-parent/browser/device/inbox acceptance and CI gaps remain explicit. No new claims of device testing or CI success. Published commit is recorded in Git history and the authorized pinned report-chat handoff.

## Roadmap continuation — 19 October 2026: truthful profile status and reliable frontend checks

- Human explicitly expanded scope from Phase 3B-only stabilization to subsequent roadmap phases in prerequisite order, and requested review of the pinned report chat. Reviewed that chat, `docs/implementation-roadmap.md`, Phase 3A/3B plans and actual source. Historical restrictions above describe prior scope; they no longer prohibit explicitly authorized next-phase implementation. Main, production data and consent requirements remain protected.
- Removed the unconditional `No device paired` badge from child profiles: `ChildController::index` returns child rows without device state, so the screen could not establish that claim. Updated the outdated later-phase disclaimer to reference Connected Devices while correctly keeping Android approval unavailable. Regression baseline: 12 passed, 1 failed.
- Discovered a verification defect: Angular emitted the edited template, but Vitest 5 filesystem module caching executed an old template. Cache clean/touch alone did not resolve it. Added explicit runner configuration disabling `fsModuleCache` for Angular's virtual compiled modules. The same regression then passed without weakening its assertion.
- Final commands from frontend: `npm.cmd test -- --watch=false` **59 passed across 10 files**; `npm.cmd run build` passed (399.83 kB initial, 107.29 kB estimated transfer; family chunk 13.57 kB); app/spec TypeScript `--noEmit` checks passed. Changed files formatted with project Prettier; root diff check passed. Existing historical frontend results remain historical, rather than evidence for newly edited templates.
- No backend/database rerun for unchanged code. Next implementation is the roadmap's secure Android device-session prerequisite, preceding usage/location ingestion. Scheduled loop prompt updated to match the human's expanded authority.

## Roadmap continuation — 19 October 2026: Phase 3C credential persistence foundation

- Development branch: `phase3c-device-session`, based on verified/pushed Phase 3B frontend commit `bbba0f0`. User explicitly requested next-phase implementation; the scheduled automation now follows roadmap prerequisites rather than repeating only stabilization.
- Added `CredentialCipher.kt`, `DeviceCredentialStore.kt` and four `CredentialCipherTest` JVM regressions. Android Keystore non-exportable AES key, AES-GCM authenticated ciphertext with fresh nonce/version context, atomic app-private no-backup file, bounded reads, generic failures, IO execution and serialized operations. No plaintext persistence, external storage, logging, new permissions or dependency installation.
- Consulted official Android Keystore guidance: https://developer.android.com/privacy-and-security/keystore . Hardware-backed key storage is device-dependent and is not claimed here.
- Exact build setup/command from root: `. ./scripts/common.ps1`, `Set-Location apps/child-android`, `./gradlew.bat assembleDebug lintDebug testDebugUnitTest`. Initial lint correctly failed on `InputStream.readNBytes` requiring API 33; replaced it with bounded API-26-compatible reads and reran all three tasks: **BUILD SUCCESSFUL**, exit 0. XML results: CredentialCipherTest 4/4, PairingViewModelTest 3/3, zero failures/errors. Lint: 0 errors, 13 warnings. SDK tooling XML-version mismatch warning remains; did not prevent build.
- Debug APK generated at `apps/child-android/app/build/outputs/apk/debug/app-debug.apk`. `adb devices` returned an empty list. JVM tests cover crypto behavior; actual Android Keystore save/read/clear, app restart, crash recovery, missing key and backup exclusion have NOT run on device. This is a compiled/tested foundation, not end-to-end pairing completion. Approval remains disabled while lifecycle integration is unfinished.
- Added `docs/phase3c-device-session.md` with executable next slices: authenticated status/disconnect, session coordinator/restoration and failure handling, visible approval with persistence rollback, credential rotation and device acceptance. Updated roadmap current status. No backend/database changes; isolated DB suites were not unnecessarily repeated.
- Frontend checks immediately preceding this Android slice: 59/59 tests, production build and strict app/spec types passed. No main merge, force push, production modification or monitoring. Phase 4/5 remain dependent on authorization/consent/revocation prerequisites. Real verified-parent/browser/inbox/device and successful CI evidence remain outstanding.

## Roadmap continuation — 19 October 2026: authenticated device session lifecycle

- Started clean at `b88e5bb` on `phase3c-device-session`; safe fetch showed zero ahead/behind. Read the pinned report chat, roadmap, full report and actual `CompanionController`/`RequirePairedDevice` contracts. Scope is the authorized next Phase 3C slice, not new monitoring.
- Added `CompanionSessionApi`/repository for `GET companion/me` and `POST companion/disconnect` with device bearer credentials, credential format checks, no redirects/cache, timeouts and bounded JSON response. A typed 401 is distinct from connectivity/server errors. No Firebase parent token is used or credential placed in observable state/logs.
- Added injectable `DeviceSession`: serializes restoration/disconnect, publishes connected only after server validation, clears 401/revoked credentials, preserves retry credentials on network failure without claiming connected or successful revocation, and reports unavailable storage or cleanup failure explicitly. Cancellation leaves unverified retry state. Server disconnect precedes local clear; already-revoked 401 still clears locally.
- Added 12 JVM regressions for missing credentials, pending server validation, no token in UI state, revoked credential cleanup, offline restoration/retry, storage read failures, disconnect ordering, already-revoked cleanup, unconfirmed offline disconnect/retry, local cleanup failure/retry, revoked cleanup failure, operation serialization and cancellation (some cases share a test). New `DeviceSessionViewModel` restores at startup; visible status/retry and confirmed disconnect controls wired to Connected Device Home and Connection Settings. Approval remains disabled for the next milestone.
- Verification setup/command: `. ./scripts/common.ps1`; from `apps/child-android`, `./gradlew.bat assembleDebug lintDebug testDebugUnitTest`. Final source including ViewModel/Compose wiring: **BUILD SUCCESSFUL**, exit 0. XML results: CredentialCipherTest 4, DeviceSessionTest 12, PairingViewModelTest 3 — **19 passed, zero failures/errors**. Lint/build reports remain the authority for warnings; existing SDK XML-version mismatch warning persists. No blanket suppression or baseline added.
- `adb devices`: no emulator/device. Debug APK exists at `apps/child-android/app/build/outputs/apk/debug/app-debug.apk`. JVM tests use injected storage/API doubles: real Android Keystore/restart/file cleanup, HTTP adapter runtime, Compose interaction and verified-parent/browser/inbox end-to-end acceptance remain pending. No live backend request or database mutation was performed; unchanged Laravel/Angular suites were not repeated.
- Roadmap and Phase 3C checklist updated. Next bounded slice: explicit approval with durable-storage readiness, visible affirmative consent, failure compensation after code consumption and shared session state; then rotation/replay tests before Phase 4. Production endpoint remains unconfigured; successful CI and actual device acceptance remain open gates. No main merge, force push, production modification, hidden collection or monitoring permissions.

## Roadmap continuation — 19 October 2026: explicit Android pairing approval

- Started clean at `d740a7c` on `phase3c-device-session`; safe fetch zero ahead/behind. Reviewed pinned report chat, latest roadmap/report and existing API/storage/session code. Implemented the next authorized Phase 3C milestone only.
- Added encrypted atomic storage-readiness probing before invitation consumption, rejecting an existing credential or unresolved compensation. Approval is serialized with restore/disconnect in the shared session. The visible Android button requires a current successful preview and an affirmative tap; code edits invalidate preview, cancellation before sending never approves, and duplicate/edit/cancel attempts after sending are blocked while completion/recovery runs.
- After approval, persist and read back the credential before validating server status. Storage/write/read-back failure triggers compensating companion disconnect and local cleanup. If compensation or cleanup fails, publish explicit recovery state and retain the token only in memory for disconnect retry; parent-side revocation instructions remain visible. Credentials never enter observable UI state; Approval diagnostic output is redacted.
- An initiated transaction finishes persistence/compensation despite coroutine/UI cancellation. This is NOT a process-death/force-stop/power-loss guarantee. A lost approval response can consume an invitation without returning a credential; the UI reports uncertainty and directs parent device-list review/revocation rather than claiming success. Durable approval idempotency/recovery across process death is not implemented. No monitoring permissions or collection are enabled.
- Added 11 approval transaction JVM tests and 4 preview/consent regressions. Tests cover storage readiness before API, existing credential protection, failure/uncertainty, write/read-back compensation, offline and cleanup recovery, unverified saved state, immediate revocation, UI teardown during approval, preview requirement, cancellation, stale code, duplicates and expired/failed preview. Existing preview, session and cipher controls remain.
- Exact Android commands: `. ./scripts/common.ps1`; from `apps/child-android`, `./gradlew.bat assembleDebug lintDebug testDebugUnitTest`: **BUILD SUCCESSFUL**, exit 0. XML: CredentialCipherTest 4, DeviceSessionTest 12, PairingApprovalTest 11, PairingViewModelTest 7 = **34 passed**, zero failures/errors. Lint 0 errors/13 warnings. APK generated at `apps/child-android/app/build/outputs/apk/debug/app-debug.apk` (15,221,530 bytes).
- Updated parent-family disclaimer to describe explicit companion approval rather than incorrectly saying unavailable. From frontend: `npm.cmd test -- --watch=false`: **59 passed across 10 files**; `npm.cmd run build`: passed (399.83 kB initial / 107.25 kB estimated transfer, family chunk 13.59 kB). App/spec `tsc --noEmit` checks passed. Changed template Prettier and root diff check passed.
- `adb devices` remains empty. JVM tests use injected API/storage and do NOT prove real Keystore writes, live HTTP, on-device consent, restart, backup exclusion or compensation UI behavior. Real verified-parent/browser/inbox/device and CI gates remain open. No backend/database changes or development data access; unchanged Laravel suites were not rerun. Main/production unchanged.
- Roadmap and Phase 3C checklist updated. Next implementation milestone: credential rotation with isolated SQLite/PostgreSQL replay/concurrency/revocation tests, followed by client recovery integration. Phase 4 remains behind prerequisite authorization/consent acceptance, rather than treating this compiled workflow as production-complete pairing.

## Roadmap continuation — 19 October 2026: backend credential rotation and revocation races

- Started clean at `be55c52` on `phase3c-device-session`; fetched zero ahead/behind. Reviewed pinned report chat, roadmap, backend AGENTS, migrations, device middleware, parent policy checks and process-based test safety guards. Implemented the next bounded Phase 3C backend slice; Android rotation calls are not enabled yet.
- Added `POST /api/v1/companion/rotate` with FormRequest validation for `next_credential_hash`, a client-staged replacement credential's SHA-256. Response returns public metadata/confirmation only, with no secret/hash. New service reloads/re-authenticates under a device-row lock, atomically rotates, rejects current/historical reuse and serializes parent revoke/self-disconnect. Status/disconnect now recheck the credential after locking, so stale middleware snapshots cannot mutate a newer session. Parent controller retains family/role authorization before service revocation.
- Added hash-only `device_credential_history` with unique hash, migration backfill and device-deletion cascade; pairing records initial hashes transactionally. Reuse prevention covers retained history for extant devices, not permanent tombstones after deletion. No history fields are serialized. Migration ran only on isolated test databases; development/production schemas and data were NOT modified or reset. Backend rollout still requires the reviewed migration.
- Added 12 feature tests and 3 PostgreSQL two-process race tests. Coverage: old-token invalidation/replay, malformed inputs/target identifiers, current/historical/cross-device reuse, parent revoke, stale service authentication, self-disconnect, family/viewer isolation, history privacy/cascade, migration backfill, competing rotations, parent-revocation dominance and global replacement uniqueness. Race workers refuse non-test PostgreSQL database names, synchronize with temporary barriers and never print credentials/passwords.
- Initial in-memory SQLite targeted run: 12 tests, **11 passed/1 failed**, 53 assertions. Failure: Laravel TrimStrings normalized a trailing newline before hash validation. Excluded `next_credential_hash` from trimming; strict malformed-input regression then passed in both full suites.
- Exact SQLite setup/command from project root: `. ./scripts/common.ps1`; `Set-Location backend`; `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL=`; `& $php vendor/bin/phpunit --do-not-cache-result`: **74 total, 70 passed/4 skipped, 333 assertions**, exit 0. Four skips are PostgreSQL-only process-lock checks.
- Exact PostgreSQL setup/command from root: `. ./scripts/start-database.ps1` (existing FamilyGuard loopback cluster already running); `Set-Location backend`; `APP_ENV=testing`, `DB_CONNECTION=pgsql`, `DB_DATABASE=familyguard_test`, `DB_HOST=127.0.0.1`, `DB_PORT=5433`, `DB_URL=`; `& $php vendor/bin/phpunit --do-not-cache-result`: **74 passed, 357 assertions**, exit 0. Includes all three new actual process races and existing approval race. Separate PostgreSQL service on 5432 untouched. Test bootstrap refuses destructive setup outside SQLite memory/reserved PostgreSQL test names.
- Ran project-local PHP `vendor/bin/pint` on only changed PHP paths, followed by `vendor/bin/pint --test` with the same paths: passed. Root diff check passed. New shared request/response JSON schemas parsed successfully with Node (syntax check, not a JSON Schema runtime test). Updated API/schema docs, roadmap and Phase 3C checklist; added rotation/recovery design in `docs/phase3c-credential-rotation.md`.
- Next Android slice must atomically stage current+pending credentials before posting the hash, retain the journal across lost responses/restart, verify the replacement before promotion, and resolve both generations during disconnect. This backend API alone does NOT implement crash-safe Android rotation. No Android/Angular reruns for unchanged client source; prior 34 Android/59 Angular results remain historical. Real device/Keystore/live HTTP/verified-parent/browser/inbox and CI acceptance remain open. No monitoring, main merge, force push or production settings changed; Phase 4 remains behind prerequisite acceptance.

## Roadmap continuation — 19 October 2026: Android rotation journal and recovery

- Started clean at `43c2b41` on `phase3c-device-session`; safe fetch zero ahead/behind. Reviewed pinned report chat, roadmap/full report, rotation contract and current Android storage/session/API code. Implemented the authorized Android handoff slice without touching development/production databases.
- Added redacted `CredentialJournal` and strict versioned codec with valid legacy single-token compatibility. Keystore storage now atomically encrypts current+pending generations, verifies stage/promotion read-back, rejects mismatched handoffs and checks file cleanup. Save cannot overwrite an unresolved/existing different credential. Keys remain in Android Keystore and ciphertext stays in no-backup storage; no credentials are put into observable state or logs.
- Added `DeviceRotation`: verify existing authorization, prepare storage, generate SecureRandom 256-bit hex token, durably stage/read back before sending only SHA-256 of the UTF-8 hex token, verify pending authorization before promotion. Startup only probes/reconciles; it never silently rotates. Lost/uncertain responses retain the SAME candidate. Disconnect tries pending/current/pending as needed, and clears only after confirmed server revocation. Ambiguous both-401/offline outcomes retain recovery state and direct parent revocation.
- Shared DeviceSession restore/disconnect now honors pending journals; an explicit confirmed rotation/recovery button is wired through the ViewModel. No automatic rotation, collection, new permissions or dependencies. In-progress cancellation retains staged recovery material; restart protocol is tested with reconstructed coordinators and storage/API doubles, not actual OS process death.
- Added 23 JVM tests: 5 journal/crypto/vector tests and 18 rotation/recovery tests. Initial full run: **53 passed/1 failed of 54** because the cancellation fixture expected a fixed secret while DeviceSession used production randomness. Made the generator injectable (production default remains SecureRandom), used a deterministic test candidate, and added no-credential, pre-staging revocation and pre-dispatch cancellation controls.
- Exact commands: `. ./scripts/common.ps1`; from `apps/child-android`, `./gradlew.bat assembleDebug lintDebug testDebugUnitTest`: final **BUILD SUCCESSFUL**, exit 0. XML totals: CredentialCipherTest 4, CredentialJournalTest 5, DeviceRotationTest 18, DeviceSessionTest 12, PairingApprovalTest 11, PairingViewModelTest 7 = **57 passed**, zero failures/errors. Lint 0 errors/13 warnings. Generated APK remains `apps/child-android/app/build/outputs/apk/debug/app-debug.apk`. Root diff validation passed.
- SDK inspection: `%LOCALAPPDATA%/Android/Sdk/emulator/emulator.exe` is NOT installed; no local system-image directory was reported. This is the concrete native acceptance setup gap, beyond simply having no connected device. Actual Keystore/AtomicFile persistence, file-crash behavior, live rotation HTTP, UI confirmation and real restart/backup testing remain unexecuted. Do not call JVM protocol tests proof of platform durability.
- Conservative recovery UX remains incomplete: both rejected candidates preserve the journal, and this slice has no in-app erase action for unconfirmed rotation state. Parent-side revocation plus an explicit local recovery/reset flow need completion and native acceptance. Backend migration remains tested only on isolated databases and has not been applied to development/production. Real verified-parent/browser/inbox and CI gates also remain open.
- Updated roadmap and Phase 3C rotation/session documentation. Backend and Angular source unchanged, so their previous passing results remain historical and were not repeated. Next bounded work: inspect hardware/SDK prerequisites, install/configure an authorized local test emulator if feasible, add/run actual Keystore journal instrumentation, and resolve confirmed recovery UX gaps. Do not start Phase 4 collection before prerequisite acceptance; main/production unchanged.

## Roadmap continuation — 19 October 2026: native test harness and emulator preparation

- Started at `dbe7c18` on `phase3c-device-session`; fetched safely with no upstream changes. Read the pinned report chat and roadmap. The human chose **Continue emulator setup** instead of connecting a physical phone. No Phase 4 collection was started.
- Added pinned AndroidX instrumentation runner dependencies (core 1.7.0, runner 1.7.0, ext.junit 1.3.0) and eight Android Keystore/AtomicFile tests. Each test uses a UUID-namespaced no-backup subdirectory and Keystore alias; it never clears the app's normal credential. Constructor injection preserves the default production alias. Cases cover encrypted persistence, reconstructed journal/stage/promotion, legacy upgrade, missing key, tampering, stale generations/overwrite, clear, and aborted atomic write. These are COMPILED, NOT EXECUTED tests; reconstructing a store is not an OS process-death test, and checking no-backup placement is not a backup/restore acceptance test.
- Exact commands, after `. ./scripts/common.ps1`, from `apps/child-android`: `./gradlew.bat assembleDebug assembleDebugAndroidTest lintDebug testDebugUnitTest` passed (exit 0, 84 tasks). Corrected a nullable-parent warning in test cleanup; final `./gradlew.bat assembleDebugAndroidTest lintDebug` passed (exit 0, 61 tasks). JVM XML totals: **57 passed**, zero failures/errors. Lint: **0 errors / 13 warnings**. Both app and instrumentation APKs exist.
- `./gradlew.bat connectedDebugAndroidTest` FAILED (exit 1): `com.android.builder.testing.api.DeviceException: No connected devices!`; **zero instrumented tests executed**. No runtime Keystore, actual restart, UI or live API success is claimed. Unchanged Angular/backend suites were not repeated; their earlier results remain historical.
- Installed SDK emulator **37.2.12.0 / build 16428233** using `android.exe --sdk=<SDK> sdk install emulator@37.2.12`. Initial download failed with `io: unexpected end of file`; retry succeeded. Installed `system-images/android-26/default/x86_64@1.0.0` successfully. New Android CLI uses slash package identifiers; the legacy `avdmanager.bat` uses semicolons.
- Created unused AVD **FamilyGuard_API26_Test**, package `system-images;android-26;default;x86_64`, Pixel 2 profile; no force/overwrite. Local ignored directory `.local/android-avd/FamilyGuard_API26_Test`. Reduced RAM to 1024 MB, display 720x1280 at 320 dpi, data partition 2 GB, cameras/audio input off; 2 CPUs. `emulator.exe -list-avds` lists it. API 26 exercises the minimum supported platform; later current-API testing remains required. No emulator was booted.
- Host checks: firmware virtualization/SLAT/VM monitor available; hypervisor not running. `HypervisorPlatform` and `VirtualMachinePlatform` InstallState 2 (disabled). About 7.7 GB RAM, only about 0.5 GB free at inspection. `emulator-check.exe accel` exits **6**: `Android Emulator hypervisor driver is not installed on this machine`. ADB still lists zero devices. Do not disable Windows security controls to work around this.
- Windows administrator launch for `Enable-WindowsOptionalFeature -Online -FeatureName HypervisorPlatform -All -NoRestart` was **cancelled by the user**. No feature enable, reboot or successful elevated setup occurred. The helper is local/ignored and was not committed or retried. Remaining setup: enable Windows Hypervisor Platform through an administrator, restart Windows when appropriate, free memory, confirm acceleration, then boot the prepared AVD and run connected tests. See `docs/android-native-acceptance.md` for exact paths/commands. Official guidance: https://developer.android.com/studio/run/emulator-acceleration .
- Phase 3C still needs actual native/live HTTP/verified-parent/browser/inbox acceptance, controlled ambiguous-rotation local recovery UX, and successful CI evidence. New backend migration remains isolated-test-only; development/production schemas/data untouched. Phases 4–6 are not completed. No main merge, force push, unrelated changes or production setting changes.

## Roadmap continuation — 19 October 2026: explicit local pairing recovery

- Started clean at `a5c23c1` on `phase3c-device-session`; `git fetch origin` and ahead/behind comparison returned 0/0. Read latest pinned report context, roadmap and current Android session/storage/rotation/UI code. Scope remains Phase 3 prerequisite completion; no Phase 4 collection or Windows feature retry.
- Confirmed recovery UX gap: ambiguous both-401 rotation journals and unreadable credentials were retained without a visible local erase option. Added **Remove local pairing recovery data** in Connected Device Home and Connection Settings, available only in ERROR/OFFLINE recovery states. Dialog directs Disconnect first, parent Connected Devices revocation, and warns that local erase cannot revoke server access or undo an in-flight request. An unchecked acknowledgment plus separate confirmation is required; Cancel/dismiss resets acknowledgment. The app explicitly cannot verify the parent's revocation from that acknowledgment.
- Session coordinator independently requires acknowledgment and rechecks recovery eligibility under the shared operation mutex. It clears storage and verifies both ordinary credential and rotation-journal absence before dropping in-memory compensation credentials or publishing UNPAIRED. Local erase sends no HTTP request and never claims server revocation. Active/connected/unpaired sessions cannot use this recovery action; stale confirmation after restored authorization is rejected. Once cleanup starts it finishes despite caller/UI coroutine cancellation while holding the operation lock; this is not an OS process-death guarantee.
- Cleanup exceptions, silent failed deletes or unreadable read-back publish ERROR. A same-process cleanup-unverified latch blocks approval and restoration until explicit cleanup retry verifies removal, including when a clear succeeded but the read-back failed. Latch is in memory, not a durable revocation record; on process restart real storage is re-read and server validation remains mandatory. Parent server revocation is still a separate requirement, not established by erasure.
- Added 9 meaningful JVM regressions: acknowledgment/no network/no secret state; connected/unpaired denial; unreadable credential recovery; failed-clear approval block/retry; silent failed clear; failed read-back even after successful delete; stale-confirmation/restore serialization; caller cancellation during cleanup; in-memory compensation-token removal. Tests use injected storage/API doubles and do not prove platform erasure or UI interactions.
- Exact commands after `. ./scripts/common.ps1`, from `apps/child-android`: `./gradlew.bat testDebugUnitTest --tests com.familyguard.child.data.LocalPairingRecoveryTest` passed (exit 0, 9 tests). Final source `./gradlew.bat assembleDebug assembleDebugAndroidTest lintDebug testDebugUnitTest` passed (exit 0, 84 tasks; 16 executed/68 up-to-date). JVM XML: **66 tests passed**, zero failures/errors. App and test APKs generated; lint **0 errors / 13 warnings**. Root diff check passed. No blanket warning suppression.
- Eight Keystore instrumentation tests remain compiled/unexecuted. Did not repeat connected tests, acceleration checks or cancelled elevation without a changed prerequisite. Windows acceleration/admin/restart, real native/UI/process-death/live HTTP, verified-parent/browser/inbox, current API and successful CI acceptance remain open. Unchanged Angular/backend checks were not repeated; development/production databases and schemas untouched. Phase 3 has more verified implementation, not release sign-off. Phases 4–6 remain pending prerequisite acceptance. No main merge, force push or production changes.

## Roadmap continuation — 19 October 2026: bounded pairing response transport

- Started clean at `e0adad3` on `phase3c-device-session`; safe fetch ahead/behind 0/0. Reviewed pinned report context, roadmap/full report, actual pairing controller and both Android HTTP adapters. Native prerequisite check still shows acceleration exit 6 and no ADB devices; no repeated connected-test run, elevation retry or reboot.
- Review confirmed approval-loss limitation: backend consumes invitation and stores only a server-generated credential hash, returning the plaintext once. An absent/lost response cannot be safely recovered by replaying the consumed invitation. Existing UI parent-device-list review/revocation guidance remains necessary. No automatic approval retry, consent bypass, replay acceptance or new server recovery contract was added.
- Confirmed separate implementation defect: `PairingRepository` used unbounded `bufferedReader().readText()`, unlike the bounded companion adapter. Replaced both adapters' response reads with a shared byte-bounded reader. Maximum accepted body 65,536 bytes, at most 65,537 bytes consumed before rejecting; strict UTF-8 decoding rejects malformed/truncated input before JSON parsing. Streams close on success/failure; size/encoding errors never echo response content. Pairing now explicitly disables URLConnection caching, matching companion behavior. Existing no-redirect, timeout, status-code and bearer handling remains.
- Added seven JVM stream regressions: exact boundary/closure; oversized early cutoff without echoing synthetic credential; one-byte chunks/multibyte family names; byte rather than character limit; malformed/truncated UTF-8; read failure and closure; zero-progress read/empty-body behavior. Real JVM streams are exercised, not Android URLConnection/JSONObject/live HTTP. No new dependencies or permissions.
- Exact commands after `. ./scripts/common.ps1`, from `apps/child-android`: `./gradlew.bat testDebugUnitTest --tests com.familyguard.child.data.ApiResponseReaderTest` passed (exit 0, 7 tests). Final `./gradlew.bat assembleDebug assembleDebugAndroidTest lintDebug testDebugUnitTest` passed (exit 0, 84 tasks; 12 executed/72 up-to-date). XML: **73 JVM tests passed**, zero failures/errors. Lint **0 errors / 13 warnings**. Debug app/test APK builds passed; eight instrumentation tests remain compiled/unexecuted. Diff validation passed.
- No backend/Angular source changes or unchanged suite reruns; no development/production database/schema access or mutation. Main unchanged. Lost-approval/process-death recovery, actual native/UI/live HTTP, verified-parent/browser/inbox, current API and CI acceptance remain explicit. Windows acceleration still needs administrator enable/restart; no security features disabled. Phase 4 remains gated; Phases 4–6 are not completed by transport hardening.

## Roadmap continuation — 19 October 2026: backend staged-approval prerequisite

- Started clean at `9e7e952` on `phase3c-device-session`; safe fetch ahead/behind 0/0. Read pinned report context, roadmap/full report, backend rules, pairing controller/concurrency fixtures and rotation history service. Implemented the next backend prerequisite for approval response-loss recovery, without claiming the Android handoff exists.
- Added ApproveDevicePairingRequest and extracted transactional DevicePairingService. Optional `credential_hash` accepts exactly 64 lowercase hex characters without whitespace normalization. Omission preserves legacy server-generated token response. Staged mode stores only the hash and returns the device descriptor without plaintext token/hash. Ownership remains invitation-derived; client target IDs/plaintext token are prohibited. Expiry, affirmative approval and one-use invitation locking remain unchanged; replay is still 404 even for identical staged requests.
- Global unique credential-history reservation rejects active/rotated/revoked hash reuse across devices/families. Device creation, history reservation and invitation consumption are one transaction, so a losing concurrent hash claim rolls back its provisional device and keeps its invitation unconsumed. No new migration; existing history migration remains applied ONLY to isolated test databases, never development/production.
- Added five staged-approval feature tests for response-independent bearer use/no credential echo/hash-not-bearer, revocation, validation/ownership/plaintext rejection without consumption, replay, active/historical cross-family reuse rollback, expiry and consent. Expanded real two-process PostgreSQL races with staged same-invitation and separate-invitation shared-hash cases, retaining legacy race. Workers output status codes only; existing reserved test-database guard remains.
- Initial targeted SQLite: 11/12 passed; the validation matrix hit the genuine request rate limiter (429) after too many cases. Disabled only ThrottleRequests within that single validation test to isolate validation; runtime throttling and separate throttle tests unchanged. Initial full PostgreSQL: 80/81 passed, with the new separate-invitation race failing an old assertion that the first row must be consumed. Exact one-winner/status/history/consumption checks passed; removed the redundant order-dependent assertion rather than weakening those invariants.
- Exact setup: `. ./scripts/common.ps1`; backend commands use project-local `$php`. SQLite overrides `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `DB_URL=`; `& $php vendor/bin/phpunit --do-not-cache-result`: **81 total / 75 passed / 6 PostgreSQL-only skipped, 404 assertions**, exit 0. Fixture-only PostgreSQL assertion correction does not affect skipped SQLite cases.
- PostgreSQL: `. ./scripts/start-database.ps1` confirmed existing FamilyGuard cluster already running; overrides `APP_ENV=testing`, `DB_CONNECTION=pgsql`, `DB_DATABASE=familyguard_test`, `DB_HOST=127.0.0.1`, `DB_PORT=5433`, `DB_URL=`; final `& $php vendor/bin/phpunit --do-not-cache-result`: **81 passed / 441 assertions**, exit 0. Six process-race cases include three pairing and three rotation races. No development/production reset/migration or service 5432 change.
- Pint applied only to changed PHP files; final whole-project `& $php vendor/bin/pint --test` passed. PHP syntax checks of new service/request passed. New shared request/response JSON schema files parsed with PowerShell ConvertFrom-Json (syntax validation, not a schema conformance suite). Root diff validation passed. Added `docs/phase3c-staged-approval.md` specifying response semantics and unimplemented client prerequisites.
- Android/Angular source unchanged, so their previous results remain historical (Android 73 JVM tests; eight native tests compiled/unexecuted). No repeated client suites, native connection attempt, cancelled elevation or Windows changes. Android must still implement encrypted pending-approval staging before request, restart/uncertain-request handling, no silent 401 cleanup of in-flight approval, explicit consent/cancel and recovery tests. This backend slice alone does NOT solve approval process death/lost response for the current app. Native/UI/live HTTP/verified-parent/browser/inbox/current API/CI gates remain open. Phase 4–6 not completed; main/production unchanged.

## Roadmap continuation — 19 October 2026: Android durable staged-approval handoff

- Human requested continued phases. Started clean at `6a04895` on `phase3c-device-session`; safe fetch ahead/behind 0/0. Reviewed pinned report context, roadmap/full report, staged approval contract and current session/storage/rotation/API code. Implemented next Phase3 recovery prerequisite; no Phase4 collection before acceptance.
- Added an explicit FGA1 approval-pending journal marker, separate from ordinary FG2 rotation/session journals, with strict/redacted codec and valid legacy raw/FG2 compatibility. Keystore storage atomically stages only into empty storage, verifies candidate/marker read-back and confirms only the matching approval candidate. Save, stage/promote rotation and direct rotation guard cannot overwrite/reinterpret pending approval. No invitation is persisted; credential remains encrypted in no-backup storage.
- PairingRepository now implements hash-only staged approval, rejecting a plaintext-token-mode response and checking paired descriptor. Production DeviceSession chooses the handoff only with staging-capable store/API; old injected implementations retain legacy behavior and existing tests. SecureRandom candidate generated and stored/read-back BEFORE explicit approval request. Same candidate status verification reconciles lost response without invitation replay; startup never auto-approves. Connected state requires bearer validation and local confirmation/read-back.
- Pending-approval 401 is uncertainty, not proof no request can commit: retain journal and give parent review/revocation guidance. Offline and failed confirmation remain unverified/recoverable. Pending disconnect clears only after confirmed server disconnect; 401/offline retains data. Existing explicitly acknowledged local erase remains available with honest no-server-revocation warning. An initiated approval finishes despite UI coroutine cancellation; this is not a tested force-stop/power-loss guarantee. Staging may precede a crash before sending; conservative recovery still retains it and never silently resubmits.
- Added 12 JVM protocol/codec regressions using encoded journal and injected storage/API: stage-before-hash; stage/write/read-back failure; lost response after commit; reconstruction and later server commit after initial401; offline restoration; rejected/confirmed disconnect; failed confirmation; pending overwrite/rotation/erase guards; cancellation before/after send; cleanup failure; strict marker/redaction/legacy compatibility. Existing preview/affirmative-consent tests remain passing. Added 1 native Keystore case for pending-marker reconstruction, overwrite/rotation denial and confirmation; **9 native tests compile, none executed**.
- Commands after `. ./scripts/common.ps1`, from `apps/child-android`: preliminary `./gradlew.bat testDebugUnitTest` passed on the initial implementation/old73-test baseline. Final source/tests `./gradlew.bat assembleDebug assembleDebugAndroidTest lintDebug testDebugUnitTest`: **BUILD SUCCESSFUL**, exit0, 84tasks (19executed/65up-to-date). XML: **85 JVM tests passed**, zero failures/errors. Lint **0 errors/13warnings**. Debug app/test APKs built. Root diff check passed. No new dependencies, Android permissions or monitoring.
- JVM reconstruction is not actual Android process-death, Keystore, UI or network adapter runtime proof. Native/real HTTP/verified-parent/browser/inbox/currentAPI/CI acceptance remains open; Windows acceleration still needs administrator enable/restart after cancelled elevation. Did not retry unchanged connected tests or Windows elevation. No backend/Angular source changes or repeated suites; preceding isolated backend81-pass PostgreSQL evidence remains historical. Backend history migration still only isolated-test applied; no development/production data/schema changes, main merge, force push or production settings changed. Phase4–6 not claimed complete.

## Roadmap continuation — 19 October 2026: HTTP adapter acceptance and approval replay fix

- Human requested continuation. Started clean at `7a02162` on `phase3c-device-session`; safe fetch ahead/behind0/0. Reviewed pinned report context, roadmap/full report and real Android adapters/backend companion disconnect response. Implemented executable JVM HTTP acceptance while native testing remains blocked.
- Added internal connection-factory constructors to pairing/companion adapters; public default constructors still use configured URLs directly. Endpoint validation still runs before opening a connection; tests map only physical connections to 127.0.0.1 ephemeral ports. No production configuration, permissive endpoint switch or security bypass. Pinned test-only JSON-java20250517 and MockWebServer4.12.0 (matching existing OkHttp runtime). JSON release verified at https://github.com/stleary/JSON-java/releases/tag/20250517 . No app runtime dependency added.
- Twelve real loopback HTTP tests cover staged hash-only explicit consent/no bearer, Unicode preview, legacy response/redaction, bearer status/flags, rotation hash and disconnect empty body, typed401 versus503 without error-body echo, redirects without forwarding bearer, byte-limit/UTF8/malformed/missing JSON, wrong staged/rotation responses, validation before opening a connection, and lost approval response. Server fixtures are synthetic; no Firebase credentials, production requests or database access.
- Harness repair: the first JDK HttpServer approach failed compilation because Android test compilation does not expose com.sun.net.httpserver. Replaced it with the matching MockWebServer test dependency; initial10 HTTP tests passed. Adding two cases exposed a JUnit non-void inferred return in the malformed-JSON test; explicitly ended it with Unit. The actual 12-test run then reproduced one product failure: buffered JVM HttpURLConnection automatically resent an approval POST after the server received it and dropped the response (11passed/1failed).
- Fixed PairingRepository by setting fixed-length streaming for the serialized UTF8 payload before obtaining outputStream, matching companion POST handling. No global retry property changed, application approval replay added, or backend consumed-code acceptance weakened. The dropped-response server now records exactly one approval POST. Android-specific retry behavior still requires native acceptance; JVM evidence is not a claim about all platform implementations.
- Exact commands after `. ./scripts/common.ps1`, from `apps/child-android`: `./gradlew.bat testDebugUnitTest --tests com.familyguard.child.data.ApiAdapterHttpTest` final **12passed**, exit0. Full `./gradlew.bat assembleDebug assembleDebugAndroidTest lintDebug testDebugUnitTest`: **BUILD SUCCESSFUL**, exit0,84tasks(13executed/71up-to-date). XML: **97 JVM tests passed**, zero failures/errors. Lint **0errors/13warnings**. App/test APK builds passed. Kotlin's existing jvmTarget DSL deprecation warning remains; no blanket suppression. Root diff validation passed.
- Nine Keystore instrumentation tests remain compiled/unexecuted; did not repeat unchanged connection/acceleration checks or cancelled elevation. These host tests exercise actual JVM sockets/adapter code plus host JSON-java, not Android networking/JSONObject, TLS/proxy, real Laravel HTTP, native lifecycle/UI, verified-parent/browser/inbox or CI. Those acceptance gates remain open. Backend/Angular source unchanged; no unchanged suites or DB/schema mutations. Main, production settings and user work preserved; no later-phase completion claimed.
