$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$localPhp = Join-Path $projectRoot '.tools/php/php.exe'
$php = if (Test-Path $localPhp) { $localPhp } else { 'php' }
function Require-Command([string]$Name) { if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) { throw "Required command missing: $Name" } }
function Check-Exit { if ($LASTEXITCODE -ne 0) { throw "Command failed with exit code $LASTEXITCODE" } }
$localJdkRoot = Join-Path $projectRoot '.tools/jdk17'
if (Test-Path $localJdkRoot) { $localJdk = Get-ChildItem $localJdkRoot -Directory | Select-Object -First 1; if ($localJdk) { $env:JAVA_HOME = $localJdk.FullName } }
if (-not $env:ANDROID_HOME) { $defaultSdk = Join-Path $env:LOCALAPPDATA 'Android/Sdk'; if (Test-Path $defaultSdk) { $env:ANDROID_HOME = $defaultSdk } }

# Use the pinned project runtimes in this terminal, without changing machine PATH.
foreach ($toolDirectory in @((Join-Path $projectRoot '.tools/php'), (Join-Path $projectRoot 'frontend/node_modules/.bin'))) {
    if ((Test-Path $toolDirectory) -and $toolDirectory -notin ($env:PATH -split ';')) { $env:PATH = $toolDirectory + ';' + $env:PATH }
}
