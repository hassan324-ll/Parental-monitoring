$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$localPhp = Join-Path $projectRoot '.tools/php/php.exe'
$php = if (Test-Path $localPhp) { $localPhp } else { 'php' }
function Require-Command([string]$Name) { if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) { throw "Required command missing: $Name" } }
function Check-Exit { if ($LASTEXITCODE -ne 0) { throw "Command failed with exit code $LASTEXITCODE" } }
$localJdkRoot = Join-Path $projectRoot '.tools/jdk17'
if (Test-Path $localJdkRoot) { $localJdk = Get-ChildItem $localJdkRoot -Directory | Select-Object -First 1; if ($localJdk) { $env:JAVA_HOME = $localJdk.FullName } }
if (-not $env:ANDROID_HOME) { $defaultSdk = Join-Path $env:LOCALAPPDATA 'Android/Sdk'; if (Test-Path $defaultSdk) { $env:ANDROID_HOME = $defaultSdk } }
