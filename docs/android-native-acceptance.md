# Android native acceptance setup

Status, 19 October 2026: emulator and system image installed; AVD prepared but not booted. Nine instrumented tests compile; none have executed. All 85 JVM tests pass after the staged approval handoff milestone. Windows acceleration is the current blocker. This setup does not close Phase 3 acceptance.

## Windows prerequisite

The Windows administrator prompt was cancelled. Windows Hypervisor Platform remains disabled, and no reboot occurred. An administrator can enable **Windows Hypervisor Platform** in **Turn Windows features on or off**, then restart Windows when ready. Do not disable Core Isolation or other Windows security controls. Close unnecessary applications to free memory before starting the emulator. This machine has about 8 GB total RAM and had only about 0.5 GB free during inspection.

Official guidance: [Android emulator acceleration](https://developer.android.com/studio/run/emulator-acceleration).

## Prepared AVD and validation

Name: `FamilyGuard_API26_Test`. AOSP API 26 x86_64, 1024 MB RAM, 2 CPUs, 720x1280, 2 GB data partition. API 26 covers the project's minimum SDK; current target API acceptance is an additional gate. AVD files are local/ignored under `.local/android-avd/FamilyGuard_API26_Test`. No existing AVD was overwritten.

From PowerShell:

```powershell
Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard'
. ./scripts/common.ps1
$sdkPath = Join-Path $env:LOCALAPPDATA 'Android\Sdk'
& "$sdkPath\emulator\emulator-check.exe" accel
& "$sdkPath\emulator\emulator.exe" -list-avds
```

Continue only after acceleration is available. In Android Studio, select `FamilyGuard_API26_Test` in Device Manager and start it. Alternatively:

```powershell
& "$sdkPath\emulator\emulator.exe" -avd FamilyGuard_API26_Test -no-snapshot -no-audio
```

After boot completes:

```powershell
& "$sdkPath\platform-tools\adb.exe" devices
Set-Location apps/child-android
./gradlew.bat connectedDebugAndroidTest
```

Expected evidence is nine executed instrumentation tests with zero failures, not merely a built test APK. Reports: `app/build/reports/androidTests/connected/debug/index.html` (check actual generated output after a successful run). Do not wipe an AVD or clear app data that contains a real pairing without explicit revocation/recovery handling.

## Build and launch

```powershell
./gradlew.bat assembleDebug assembleDebugAndroidTest lintDebug testDebugUnitTest
```

App APK:
`C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\apps\child-android\app\build\outputs\apk\debug\app-debug.apk`

Test APK:
`C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\apps\child-android\app\build\outputs\apk\androidTest\debug\app-debug-androidTest.apk`

Install after the emulator appears as `device` in ADB:

```powershell
& "$sdkPath\platform-tools\adb.exe" install -r app/build/outputs/apk/debug/app-debug.apk
& "$sdkPath\platform-tools\adb.exe" shell am start -n com.familyguard.child.debug/com.familyguard.child.MainActivity
```

Debug API base URL is `http://10.0.2.2:8000/api/v1/`. Live pairing requires a deliberately configured local backend and verified parent; do not automatically migrate/reset development data to enable it. The new rotation migration has only been tested on isolated databases and needs a reviewed rollout before live use.

## What these tests establish

Nine tests target actual Android Keystore and AtomicFile behavior using unique test aliases and directories: encrypted persistence, reconstructed current/pending journal and promotion, legacy upgrade, missing key, tampering, stale writes, clear, aborted atomic write. The new pending-approval case also verifies reconstruction, overwrite/rotation denial and explicit confirmation. The default app credential is preserved. No plaintext test secret is used as a real credential.

Even when these pass, actual process death/restart, backup/transfer exclusion, live HTTP rotation/revocation, visible consent and compensation UI, verified-parent/browser/inbox and CI acceptance remain separate work. Current both-401 rotation recovery retains the journal automatically; an explicitly acknowledged/confirmed recovery-only local erase is now implemented with JVM coverage. It does not revoke server access, and actual native/UI acceptance remains pending. Do not start collection based only on build success.
