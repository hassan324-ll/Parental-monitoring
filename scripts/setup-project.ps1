. (Join-Path $PSScriptRoot 'common.ps1')
Require-Command npm.cmd
Require-Command composer
Require-Command php
Push-Location (Join-Path $projectRoot 'frontend')
try { & npm.cmd ci --offline=false; Check-Exit } finally { Pop-Location }
Push-Location (Join-Path $projectRoot 'backend')
try { & composer install --no-interaction; Check-Exit; if (-not (Test-Path '.env')) { Copy-Item '.env.example' '.env'; & $php artisan key:generate; Check-Exit }; Write-Host 'Configure PostgreSQL credentials in ignored .env, then run php artisan migrate.' } finally { Pop-Location }
