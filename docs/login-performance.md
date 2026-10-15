# Login response-time correction — 2026-10-15

The installed Firebase 13 SDK loads account information before signInWithEmailAndPassword returns. FamilyGuard then reloaded the same profile in login() and again in the immediate dashboard guard, adding two serial provider round trips.

A disposable synthetic account probe on this laptop measured:

- Firebase SDK sign-in (including its own profile lookup): 1,549 ms.
- Additional login reload: 601 ms.
- Additional guard reload: 786 ms.
- Combined probe sequence: 2,936 ms; redundant requests alone: 1,387 ms.

The probe used an unverified reserved-domain account and explicitly timed the requests used by the verified-login path. It is not a measured end-to-end verified browser login. The test account was deleted. No passwords, ID tokens or account identifiers were logged. Internet/provider latency and development-server compilation can still affect perceived performance.

Correction: use the fresh sign-in UserCredential metadata for the verified-email check, then hand that same active user to the immediate route guard once. The handoff is memory-only, limited to five seconds, consumed once, cleared on login/register/logout, and ignored after identity changes or expiry. Restored sessions and later guard calls still reload account data and reject refresh errors. Unverified users are signed out. Laravel's server-side token verification and family authorization are unchanged.

Validation: 34 tests in seven Angular test files passed; strict app/spec TypeScript checks, Prettier and production build passed (395.77 kB initial). Regression tests cover no duplicate reload on immediate login, reload on subsequent navigation, expired handoff, logout, changed active user and provider reload failure. Full verified browser login timing remains for the owner to retest.

The pre-existing local frontend/angular.json Angular CLI configuration change was preserved and excluded from this fix.