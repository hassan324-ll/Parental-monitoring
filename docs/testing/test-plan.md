# Verification and regression tests

Use the supported local PHP runtime (or equivalent PHP with OpenSSL, mbstring and PDO drivers) from apps/backend:

~~~powershell
& '..\..\.tools\php\php.exe' vendor/phpunit/phpunit/phpunit
& '..\..\.tools\php\php.exe' vendor/phpunit/phpunit/phpunit --configuration=phpunit.postgres.xml
& '..\..\.tools\php\php.exe' vendor/bin/pint --test
~~~

Default PHPUnit uses in-memory SQLite. phpunit.postgres.xml selects the separate familyguard_test database using the local connection credentials; create this disposable database before testing a fresh clone. Tests refuse application bootstrap unless the actual database is in-memory SQLite or a dedicated familyguard_test name, before any RefreshDatabase migration can run. Never reset the normal development database.

Backend coverage includes real ephemeral RSA signatures/test certificates, expiry, audience/issuer, email verification, malformed/tampered tokens, certificate cache/rotation failures, UID/email collision handling, a deterministic PostgreSQL uniqueness race, real signed-token family authorization, viewer restrictions, invalid-auth rate limits, database constraints, indexes and full migration reset/reapply on test databases. Private test keys are generated in memory and never committed. Multi-worker load and real Firebase Admin revocation remain separate release checks.

From apps/parent-dashboard run npm test -- --watch=false, npm run build, npx tsc --noEmit -p tsconfig.app.json and -p tsconfig.spec.json, plus npx prettier --check 'src/**/*.{ts,html,scss}' tsconfig.json. No separate lint target is configured. Coverage includes fail-closed auth setup, guards, normalized token destinations, registration/login/reset/logout and asynchronous identity races. Browser smoke checks must verify unauthenticated route redirects and mobile overflow. Real signup/email delivery requires Firebase account configuration.

Android: keep JDK 17; run gradlew.bat assembleDebug testDebugUnitTest lintDebug. NO-SOURCE is not a passing unit test suite. Verify the APK with apksigner and inspect its package/manifest. Only claim launch smoke tests when ADB has a connected device/emulator. Do not add monitoring permissions as part of verification. See docs/verification-report.md for actual outcomes and remaining warnings.
