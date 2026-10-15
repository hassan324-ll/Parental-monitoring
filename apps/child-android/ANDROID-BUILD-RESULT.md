# FamilyGuard Android build result

15 October 2026, Asia/Karachi. Existing Android project: C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\apps\child-android

**Completed and verified:** Gradle configuration, SDK installation, debug compilation/packaging and APK signature inspection.
**Not tested:** installation and launch on a phone or emulator. ADB lists no connected devices and there is no configured AVD.

## Verified tools and compatibility

- SDK: C:/Users/Muhammad Hassan Ali/AppData/Local/Android/Sdk.
- SDK Platform 36 revision 2, Build Tools 35.0.0, Platform Tools 37.0.1 (ADB 1.0.41), command-line tools 23 installed from Google's official SDK repository. Downloaded command-line archive checksum matched Google's catalog.
- Gradle wrapper 8.13; Android Gradle Plugin 8.13.2; Kotlin/Compose compiler plugin 2.2.21. Existing versions retained.
- Build JDK: Temurin 17.0.20.1+1 at C:/Users/Muhammad Hassan Ali/Projects/FamilyGuard/.tools/jdk17/jdk-17.0.20.1+1. Actual Gradle JVM version verified.
- JDK 17 and JDK 21 can both run Gradle 8.13. JDK 17 is selected because AGP 8.13 documents JDK 17 and this app targets JVM 17. JDK 21 is not needed. Android Studio's bundled Java 25 should run the IDE, but cannot run this Gradle version; Java 25 requires Gradle 9.1+.

References: [AGP compatibility](https://developer.android.com/build/releases/agp-8-13-0-release-notes), [Gradle Java compatibility](https://docs.gradle.org/current/userguide/compatibility.html).

## Changes limited to the Android project

- local.properties: SDK path (ignored machine-local file).
- .gradle/config.properties: java.home points to the existing project-local JDK 17 (ignored machine-local file).
- .idea/gradle.xml: changed gradleJvm from jbr-21 to #GRADLE_LOCAL_JAVA_HOME.
- .run/app.run.xml: shared Android App configuration named app, module FamilyGuard.app, launch default activity, Gradle before-run task enabled.
- ANDROID-BUILD-RESULT.md: this report.

No Kotlin source, monitoring features, Angular or Laravel files were changed. No project was recreated. No global Java/PATH settings or Windows hypervisor settings were changed.

## Actual execution results

1. gradlew.bat --version: passed, Gradle 8.13 using JVM 17.0.20.1.
2. gradlew.bat help --console=plain: BUILD SUCCESSFUL in 4m 47s. This verified project configuration and build-plugin resolution.
3. gradlew.bat assembleDebug --console=plain --max-workers=2: BUILD SUCCESSFUL in 6m 31s; 39 actionable tasks, 39 executed. Kotlin compilation, Java compilation, manifest/resource processing, DEX generation and debug packaging passed. No source fix was needed.
4. apksigner verify --verbose: passed; one signer, APK v2 signature valid.
5. aapt dump badging: package com.familyguard.child.debug, version 0.1.0/code 1, label FamilyGuard Companion, launch activity com.familyguard.child.MainActivity, min SDK 26, target/compile SDK 36, debuggable.
6. APK file existence and SHA-256 verified. ADB starts and responds; devices list is empty.
7. Android Studio local CLI check: Rabbit 1 / 2026.2.1, project child-android reported READY. Command-line configuration/dependency resolution and compilation are verified. The Windows capture helper failed with 'window capture timed out: timed out waiting on channel'; therefore clicking the IDE Sync action and its displayed result could not be verified. Use File > Sync Project with Gradle Files once to refresh the open IDE after these on-disk fixes.

## Verified APK

C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\apps\child-android\app\build\outputs\apk\debug\app-debug.apk

Size: 15,165,989 bytes (~14.46 MiB).
SHA-256: 1D8F30AE76A5043183B32FD83A98697120756D34B772DFF47644EE08E127CC09

A matching copy is supplied as FamilyGuard-debug.apk in the chat outputs directory. It is a debug build, not a production release.

## Launch in Android Studio

1. Open this existing child-android directory. Choose File > Sync Project with Gradle Files.
2. In Settings > Build, Execution, Deployment > Build Tools > Gradle, verify Gradle JDK is GRADLE_LOCAL_JAVA_HOME / the local JDK 17 path above. Do not select bundled Java 25 for this project.
3. Select app in the run-configuration selector. Its type is Android App, module FamilyGuard.app (the app module), and launch option is Default Activity. If the IDE has not reloaded the shared configuration, reopen the project or use Run > Edit Configurations to select the app module after sync.
4. Select a booted emulator or authorized USB device, then click Run (green triangle). Installation/launch is not yet verified because no target is connected.

## Configure an emulator

Open View > Tool Windows > Device Manager, click + / Create Virtual Device, choose a phone such as Pixel 4, then download an API 36 Google APIs x86_64 system image. Choose x86_64 for this Intel laptop. Finish the AVD with automatic graphics and approximately 2 GB RAM, then press its Play button. Wait for Android to finish booting, select the emulator beside app and click Run.

Firmware virtualization was verified enabled during initial setup, but Windows hypervisor acceleration has not been verified and no AVD/emulator package was installed by this build task. If Device Manager reports missing acceleration, follow its Windows Hypervisor Platform guidance; enabling a Windows feature may require administrator access/restart. No BIOS or Windows feature changes were made automatically. This laptop has 8 GB RAM and was under substantial memory pressure; close unused applications before running an emulator, or use a physical phone.

[Official AVD instructions](https://developer.android.com/studio/run/managing-avds).

## Install and launch with ADB

Connect your own Android 8.0+ phone with Developer options > USB debugging enabled and accept its RSA authorization prompt, or boot an AVD. With exactly one target connected, run in PowerShell:

~~~powershell
Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\apps\child-android'
$adb = Join-Path $env:LOCALAPPDATA 'Android\Sdk\platform-tools\adb.exe'
& $adb devices -l
& $adb install -r '.\app\build\outputs\apk\debug\app-debug.apk'
& $adb shell am start -n 'com.familyguard.child.debug/com.familyguard.child.MainActivity'
~~~

If multiple targets are attached, add -s <device-serial> immediately after $adb in install/start commands. These install/launch commands are instructions; they were not run without a connected target.

The current debug API base URL is http://10.0.2.2:8000/api/v1/, which targets the Windows host from an emulator. On a physical phone, the app shell can launch, but this emulator-only API address will not reach your PC; physical-device API configuration is a separate step. No backend changes were made.

## Rebuild

~~~powershell
Set-Location 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\apps\child-android'
$env:JAVA_HOME = 'C:\Users\Muhammad Hassan Ali\Projects\FamilyGuard\.tools\jdk17\jdk-17.0.20.1+1'
.\gradlew.bat assembleDebug --max-workers=2
~~~

local.properties already supplies sdk.dir, so no global SDK environment variable is necessary.

## Remaining warnings and limits

- AGP printed an SDK XML version 4/reader version 3 warning because the installed SDK tooling is newer. It did not prevent compilation or packaging.
- The existing kotlinOptions.jvmTarget DSL is deprecated. It is functional and was retained; the target remains JVM 17.
- Debug packaging could not strip two native libraries (libandroidx.graphics.path.so and libdatastore_shared_counter.so), so it included them unchanged. Packaging/signature verification passed.
- The Android CLI printed a Windows processor-group JVM warning; SDK installation succeeded.
- No emulator/phone launch, interactive UI test, lint run or instrumentation test is claimed. Firebase account configuration and the planned monitoring features remain outside this task.
