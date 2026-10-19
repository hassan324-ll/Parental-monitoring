# Phase 4H — signed-parent client-to-Laravel HTTP acceptance

Run explicitly from the repository root:

```powershell
./scripts/test-parent-http.ps1
```

This starts a temporary loopback PHP server and exercises the real Angular SharingSummaryApiService, authTokenInterceptor, HttpClient Fetch backend and strict summary parser against Laravel's actual HTTP kernel, FirebaseTokenVerifier, FirebaseParentUserResolver, parent guard, family roles, summary service and parent-revocation route. It fills the gap between mocked HTTP unit tests and real signed-parent API traffic. It is **not** real Firebase sign-in, Firebase SDK token acquisition, inbox verification, visual browser/CORS acceptance or Android native acceptance.

## Isolation and cryptographic boundary

The fixture/router reuse HttpAcceptanceEnvironment's fail-closed guard: uncached testing only, PostgreSQL exactly familyguard_test on127.0.0.1:5433, empty DB_URL. Fixtures migrate that test schema without resetting an existing database and add only unique synthetic users/families/device/data. Existing unrelated test rows are preserved. Never repoint this harness at a development/production database.

A new ephemeral2048bitRSA key signs short-lived synthetic ID tokens with the same RS256/issuer/audience/verified-email claim requirements as production. The private key stays in PHP memory and is not exported. Temporary ignored JSON contains the synthetic public certificate, ten-minute synthetic tokens and fixture identifiers. Test router replaces only Google's certificate-transport response via Http::fake, after validating the environment/explicit opt-in/fixture path. No production verifier or authentication provider is overridden, no actingAs/test identity endpoint is used, no unsigned emulator token is accepted. Production routes/bootstrap/config/auth code are unchanged. The router and fixture live under tests/Support, outside the web root.

The Angular test injects only the identity SDK's token-return boundary with those signed fixture tokens; the real interceptor sends them and the server verifies signatures and claims. Thus it does not establish actual Firebase provider availability or SDK-to-server compatibility for a live account. Real Firebase Authentication emulator tokens are unsigned and intentionally incompatible with this production verification path; do not weaken the verifier to make emulator tokens pass.

Only this dedicated test router sets synthetic-parent-http as the expected project and serves the generated certificate. Actual project settings are untouched; stray outbound Laravel HTTP requests are blocked. Test future activation grants/data are seeded in isolated fixtures; there is no runtime collection/activation/ingestion endpoint. Samples are synthetic package names and near-zero coarse coordinates.

## Tested flow

The first opt-in test case asserts owner summary/60seconds/minimized coordinates/accuracy/collectionfalse; viewer403; unrelated parent404; mismatched family/device404; missing token, unverified email, expired token and corrupted signature401; owner DELETE revocation through the real protected route, then summaryrevoked/emptyusage/nulllocation. A second opt-in case uses the real Angular DevicePairingApiService plus a separate direct HttpClient Fetch channel for simulated device requests (no Firebase parent interceptor). It verifies viewer/foreign-parent invitation denial, parent invitation creation, preview, denied approval then successful staged hash approval without returning a plaintext token, invitation replay/expiry rejection, device-versus-parent identity separation, rotation invalidating the previous bearer, parent device-list contract, viewer/foreign-parent revoke denial, real owner revocation and denial of status/rotation with the replacement bearer afterward. Candidate credentials/hashes and an already-expired invitation are synthetic fixture fields; cleanup cascades through devices/invitations/history. This does not exercise Android HTTP adapters, Keystore, a physical consent tap or process recovery. No tokens/passwords/private key are printed in normal output. Assertion failures intentionally avoid including signed tokens. Server logs and fixture files are ignored local testing artifacts, never commit them.

The runner fails on native-command failures or absent prepared fixture before server/tests start, waits for local health, and stops only its own PHP process. Cleanup checks every synthetic user's random email/UID/ID before deleting their families/memberships/users and removes the fixture JSON. On cleanup failure it reports the blocker; do not erase unrelated test data. Normal test suite skips the opt-in case and does not read a fixture. Vitest provide injects only when FAMILYGUARD_PARENT_HTTP=1; the runner restores environment values afterward. Test helper preparation uses model password hashing with the configured cost, and stores the fixture inside the DB transaction so file-write failure rolls back the inserts.

A preparation test with a synthetic command returning exit0 but producing no file verifies the runner stops before server/tests/cleanup; it makes no DB calls. This addresses observed setup behavior where a PHP console exception did not yield a useful native exit for the runner. It is not proof of every operating-system/process/cleanup failure mode.

## Remaining acceptance

Real verified-parent Firebase/browser/inbox/currentAPI, browser rendering/accessibility/CORS and tab behavior, actual Android Keystore/platform network/JSON/process death, emulator acceleration/device availability, shared-host scheduler/cache coordination and hosted CI billing remain separate gates. No collection is activated, deployment/migrations changed, main merged or automatic Codex development loop restarted by this harness.