. (Join-Path $PSScriptRoot 'common.ps1')
Require-Command npm.cmd
Push-Location (Join-Path $projectRoot 'apps/parent-dashboard')
try { & npm.cmd start -- --host=localhost --port=4200; Check-Exit } finally { Pop-Location }
