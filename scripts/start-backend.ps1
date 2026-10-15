. (Join-Path $PSScriptRoot 'common.ps1')
Require-Command $php
if (-not (Test-Path (Join-Path $projectRoot 'backend/vendor/autoload.php'))) { throw 'Run setup-project.ps1 first' }
Push-Location (Join-Path $projectRoot 'backend')
try { & $php artisan serve --host=127.0.0.1 --port=8000; Check-Exit } finally { Pop-Location }
