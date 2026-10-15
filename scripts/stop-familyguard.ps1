param([switch]$StopDatabase)
. (Join-Path $PSScriptRoot 'common.ps1')
# Select only this checkout's Laravel and Angular processes, never all PHP/Node processes.
$processes = @(Get-CimInstance Win32_Process)
$owned = @($processes | Where-Object {
    $_.CommandLine -and $_.CommandLine.IndexOf($projectRoot, [StringComparison]::OrdinalIgnoreCase) -ge 0 -and (
        ($_.Name -eq 'php.exe' -and ($_.CommandLine -match 'artisan serve.*127\.0\.0\.1.*8000' -or $_.CommandLine -match '-S 127\.0\.0\.1:8000')) -or
        ($_.Name -eq 'node.exe' -and $_.CommandLine -match '@angular[\\/]cli[\\/]bin[\\/]ng\.js.*serve.*4200')
    )
})
foreach ($process in $owned) { Stop-Process -Id $process.ProcessId -ErrorAction SilentlyContinue }
Write-Host 'Stopped FamilyGuard Laravel/Angular processes, if running.'
if ($StopDatabase) { & (Join-Path $PSScriptRoot 'stop-database.ps1') }
