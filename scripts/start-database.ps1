. (Join-Path $PSScriptRoot 'common.ps1')
$pgCtl = 'C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe'
$dataRoot = Join-Path $projectRoot '.local/postgres'
if (-not (Test-Path $pgCtl)) { throw 'PostgreSQL 18 is not installed' }
if (-not (Test-Path (Join-Path $dataRoot 'PG_VERSION'))) { throw 'FamilyGuard local database is not initialized; see infrastructure/local/README.md' }
& $pgCtl -D $dataRoot status
if ($LASTEXITCODE -ne 0) { & $pgCtl -D $dataRoot -l (Join-Path $projectRoot '.local/postgres.log') -w start; Check-Exit }
