. (Join-Path $PSScriptRoot 'common.ps1')
foreach ($toolName in @('git','node','npm.cmd','php','composer','psql','java','adb','winget')) { $command = Get-Command $toolName -ErrorAction SilentlyContinue; if ($command) { Write-Host "$toolName : $($command.Source)" } else { Write-Warning "$toolName is not on PATH" } }
Write-Host 'PHP PostgreSQL driver:'
if (Get-Command php -ErrorAction SilentlyContinue) { & $php -r 'echo extension_loaded("pdo_pgsql") ? "available" : "MISSING";' }
