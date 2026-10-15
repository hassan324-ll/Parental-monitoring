. (Join-Path $PSScriptRoot 'common.ps1')
$pgCtl = 'C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe'
$dataRoot = Join-Path $projectRoot '.local/postgres'
if (-not (Test-Path (Join-Path $dataRoot 'PG_VERSION'))) { throw 'No FamilyGuard database cluster found' }
& $pgCtl -D $dataRoot -m fast -w stop
Check-Exit
