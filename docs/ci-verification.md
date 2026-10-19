# CI coverage and acceptance status

The Verify workflow retains `foundation` and adds separate PostgreSQL and Android jobs. Permissions are read-only repository contents, with bounded job timeouts. The PostgreSQL password is an ephemeral CI-service fixture value, not a production credential; no production secrets or deployment environment is used.

- foundation: existing Angular build/unit checks, explicit app/spec TypeScript noEmit checks, Laravel SQLite in-memory and Pint.
- postgres: isolated PostgreSQL18 service mapped to loopback5433/database familyguard_test; full backend suite including actual process-race tests. No development/production database URL is configured.
- android: Temurin17, SDKplatform36/buildtools35.0.0, checked-in Gradle wrapper; assembleDebug/assembleDebugAndroidTest/lintDebug/testDebugUnitTest. Reports retained7days. This builds native tests but does NOT run an emulator or connected instrumented tests. The opt-in real-Laravel JVM integration remains separately executable via scripts/test-device-http.ps1 and is skipped in the normal CI JVM task.

## Executed validation — 19 October 2026

Downloaded actionlint1.7.12 from its official GitHub release into ignored .tools, verified the archive against the official SHA256 checksum, and ran:

```powershell
./.tools/actionlint-1.7.12/actionlint.exe -shellcheck= -pyflakes= .github/workflows/verify.yml
```

Passed. This validates Actions/YAML semantics; external ShellCheck/Pyflakes were explicitly disabled and no hosted runner was emulated. Added frontend checks `tsc --noEmit -p tsconfig.app.json` and `tsc --noEmit -p tsconfig.spec.json` passed locally. Prior local backend/Android/full HTTP results are in FULL_PROJECT_VERIFICATION_REPORT.md; unchanged suites were not repeated for this configuration-only change.

References: [actionlint release](https://github.com/rhysd/actionlint/releases/tag/v1.7.12), [Android setup action and package input](https://github.com/android-actions/setup-android).

## Hosted execution blocker

Pre-change commit17a1599 run39221493925 completedfailure within seconds. Its foundation job117957591552 reports failure with no steps/logs. The commit workflow helper returns PR-triggered runs only, so push status was read from the repository Actions-runs API instead. A job-details subresource request was unsupported by the connector; no reason was invented from that limitation. Exact billing/account/scheduling cause remains unverified. This is not evidence of a compile/test failure or a successful pipeline.

[Observed run](https://github.com/hassan324-ll/Parental-monitoring/actions/runs/39221493925).

The expanded Linux job commands, third-party setup actions, container service, report upload and cache behavior require an actual hosted run. Syntax/local command verification does not establish that those integrations executed successfully. Check the pushed workflow's run before closing CI acceptance. Native Android, verified-parent browser/inbox and release acceptance remain open; this does not enable Phase4 data collection.

## Expanded workflow publication result — 19 October 2026

Pushed workflow commit eb3bf585d251bb3d5da45f95ea38966ad8343b9b triggered run39222254744, which completedfailure. Android job117960072669, PostgreSQL job117960072753 and foundation job117960073168 all report failure with null steps/logs. No test command execution is demonstrated, and no successful hosted coverage is claimed. Do not infer a code failure or a specific account/billing cause without GitHub's error details. Inspect https://github.com/hassan324-ll/Parental-monitoring/actions/runs/39222254744 for the actionable platform message. Do not repeatedly rerun unchanged jobs while this condition remains.

## Confirmed hosted blocker from visible GitHub annotations — 19 October 2026

Inspected the actual run page39222254744 in the browser after the human supplied its screenshot. All three job annotations explicitly state: "The job was not started because your account is locked due to a billing issue." This supersedes the earlier unknown-cause assessment. No hosted job/test started; no application compilation failure is demonstrated. The Ubuntu runner migration messages are separate notices, not the reported blocking errors.

The repository owner's GitHub account billing lock must be resolved through GitHub before hosted verification can execute. No payment, billing setting, account configuration or security control was changed, and no unchanged job was rerun. Local verification results remain independent. Preserve the distinction between a validated workflow configuration and an actual hosted pass. Visible source: https://github.com/hassan324-ll/Parental-monitoring/actions/runs/39222254744 .
