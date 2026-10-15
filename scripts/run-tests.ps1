. (Join-Path $PSScriptRoot 'common.ps1')
Require-Command npm.cmd
Require-Command php
Push-Location (Join-Path $projectRoot 'apps/parent-dashboard')
try { & npm.cmd run build; Check-Exit; & npm.cmd test -- --watch=false; Check-Exit } finally { Pop-Location }
Push-Location (Join-Path $projectRoot 'apps/backend')
try { & $php artisan test; Check-Exit } finally { Pop-Location }
$wrapper = Join-Path $projectRoot 'apps/child-android/gradlew.bat'
if ($env:ANDROID_HOME -and (Test-Path $wrapper)) { Push-Location (Split-Path $wrapper); try { & $wrapper assembleDebug lintDebug; Check-Exit } finally { Pop-Location } } else { Write-Warning 'Android checks skipped: configure ANDROID_HOME and Java; see docs/android/permissions.md' }
