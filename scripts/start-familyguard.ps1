param([ValidateRange(10, 300)][int]$TimeoutSeconds = 120)
. (Join-Path $PSScriptRoot 'common.ps1')
Require-Command npm.cmd
Require-Command node.exe
if (-not (Test-Path $php) -and -not (Get-Command $php -ErrorAction SilentlyContinue)) { throw 'PHP is missing; run setup-project.ps1 first.' }
foreach ($dependency in @('backend/vendor/autoload.php', 'backend/.env', 'frontend/node_modules/@angular/cli/bin/ng.js')) {
    if (-not (Test-Path (Join-Path $projectRoot $dependency))) { throw "Missing $dependency; run setup-project.ps1 and configure the ignored backend .env." }
}
$logRoot = Join-Path $projectRoot '.local/run'
New-Item -ItemType Directory -Path $logRoot -Force | Out-Null

function Get-OwnedListener([int]$Port) {
    $listeners = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
    foreach ($listener in $listeners) {
        if ($listener.LocalAddress -notin @('127.0.0.1', '::1')) { throw "Port $Port has a non-loopback listener. Refusing to reuse it." }
        $process = Get-CimInstance Win32_Process -Filter "ProcessId=$($listener.OwningProcess)"
        if (-not $process -or -not $process.CommandLine -or $process.CommandLine.IndexOf($projectRoot, [StringComparison]::OrdinalIgnoreCase) -lt 0) {
            throw "Port $Port belongs to another application. Stop that application or use separate documented startup commands."
        }
    }
    return $listeners.Count -gt 0
}

function Start-LocalService([string]$Name, [int]$Port, [string]$Script, [string]$Url) {
    if (Get-OwnedListener $Port) { Write-Host "Reusing FamilyGuard $Name on port $Port." }
    else {
        $scriptPath = (Join-Path $PSScriptRoot $Script).Replace("'", "''")
        $command = "& '$scriptPath'"
        $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes($command))
        $shell = (Get-Process -Id $PID).Path
        $process = Start-Process -FilePath $shell -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encoded) -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $logRoot "$Name.out.log") -RedirectStandardError (Join-Path $logRoot "$Name.err.log")
        Write-Host "Started $Name (launcher PID $($process.Id)); logs: .local/run/$Name.*.log"
    }
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        try {
            $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 3
            if ($response.StatusCode -eq 200) {
                if ($Name -eq 'backend' -and ($response.Content | ConvertFrom-Json).status -ne 'ok') { throw 'Unexpected health response' }
                if ($Name -eq 'frontend' -and $response.Content -notmatch '<app-root') { throw 'Unexpected frontend response' }
                if (-not (Get-OwnedListener $Port)) { throw 'Expected local listener is absent' }
                Write-Host "$Name ready: $Url"
                return
            }
        } catch { $lastFailure = $_.Exception.Message }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)
    throw "$Name did not become ready: $lastFailure. See .local/run/$Name.err.log and $Name.out.log. A service that started may still be running; use stop-familyguard.ps1 to stop it."
}

& (Join-Path $PSScriptRoot 'start-database.ps1')
Check-Exit
Start-LocalService 'backend' 8000 'start-backend.ps1' 'http://localhost:8000/api/v1/health'
Start-LocalService 'frontend' 4200 'start-frontend.ps1' 'http://localhost:4200/auth'
Write-Host ''
Write-Host 'FamilyGuard is running on this laptop only.'
Write-Host 'Login: http://localhost:4200/auth'
Write-Host 'Dashboard (verified sign-in required): http://localhost:4200/dashboard'
Write-Host 'API health: http://localhost:8000/api/v1/health'
Write-Host 'This launcher can close; background services remain running.'
Write-Host 'Stop: .\scripts\stop-familyguard.ps1 (add -StopDatabase to stop the private database too).'
