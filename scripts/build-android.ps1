. (Join-Path $PSScriptRoot 'common.ps1')
if (-not $env:JAVA_HOME) { throw 'Configure JDK 17 JAVA_HOME for this terminal' }
if (-not $env:ANDROID_HOME) { throw 'Complete Android Studio SDK wizard; install Platform 36, Build Tools 35.0.0 and Platform Tools. See docs/android/permissions.md' }
Push-Location (Join-Path $projectRoot 'apps/child-android')
try { & .\gradlew.bat assembleDebug lintDebug; Check-Exit } finally { Pop-Location }
