# Isolated Android adapter to Laravel HTTP acceptance

Run from the repository root:

```powershell
. ./scripts/start-database.ps1
./scripts/test-device-http.ps1
```

This runs `LaravelHttpAcceptanceTest` through the real PairingRepository, CompanionSessionRepository, DeviceSession, Laravel HTTP kernel/routes/middleware and PostgreSQL. It is a JVM test with an encoded in-memory credential store, not Android Keystore, an emulator, Compose UI or a Firebase parent login.

## Guardrails and lifecycle

- PHP fixture preparation and every HTTP request require uncached APP_ENV=testing, PostgreSQL, host127.0.0.1, port5433, database exactly familyguard_test and an empty DB_URL. Wrong settings fail before migration/fixture queries. Guard unit tests cover unsafe names, hosts/ports/drivers, cached/non-testing configuration and URL overrides.
- Uses the existing project-only PostgreSQL cluster. Applies migrations only to the isolated test database, never migrate:fresh/reset. Existing unrelated test rows are preserved.
- Creates a unique synthetic owner/family/child and three short-lived invitations. The fixture file stays under ignored `.local/http-acceptance/<UUID>.json`; invitation values are never printed. No production test endpoints, routes, injected parent identities or authentication bypass are added.
- Starts only a loopback PHP server on an ephemeral port using the guarded test router. The JVM maps adapter socket destinations to that local port through internal constructors; production endpoint validation still executes and normal constructors stay unchanged.
- After testing, stops its own server, verifies the fixture's owner/email/family match and deletes that family before its synthetic owner. Cascades remove fixture devices/invitations/history. The fixture file is then removed, and process environment overrides restored. Ignored server stdout/stderr logs remain for diagnosis. If interrupted, inspect the specific fixture and rerun guarded cleanup; never delete arbitrary families or reset a development database.

## Executed workflow

Preview, affirmative staged approval, server-verified restoration, rotation, old credential rejection, consumed-invitation replay rejection, disconnect, expired invitation rejection, server-side revocation and restoration cleanup. The revocation step calls the existing credential service through a guarded fixture CLI; it does not simulate a verified-parent HTTP login or prove parent UI behavior. An unauthenticated parent request without JSON Accept must return JSON401.

The opt-in test is explicitly skipped in normal JVM runs when FAMILYGUARD_HTTP_ACCEPTANCE is absent. The runner enables it and supplies only synthetic fixture context. Report the enabled run separately from a normal suite's skipped count; do not call the default skip a passed integration check.

## Confirmed API fix

The first live run found an unauthenticated parent GET without `Accept: application/json` returned500 because default guest redirect tried an absent login route. API guest redirects now return null, letting existing API JSON exception handling return401. Existing non-API redirect behavior is preserved. A Laravel feature regression covers absent, HTML and wildcard Accept headers. Authentication remains required.

## Outstanding acceptance

Actual Android networking/JSONObject/Keystore/process restart/UI, verified Firebase parent/browser/inbox and successful CI evidence remain open. This run uses no real Firebase credentials and requests no monitoring permissions. Nine instrumented native tests are still unexecuted pending Windows acceleration. No Phase4 collection is enabled by this harness.

The runner now passes task-level --rerun so backend/fixture changes always cause actual live test execution; Gradle UP-TO-DATE is not accepted as a fresh HTTP verification result.
