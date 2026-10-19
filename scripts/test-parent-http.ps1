# Opt-in actual Angular HttpClient/interceptor/parser to signed-fixture Laravel HTTP on isolated PostgreSQL.
. (Join-Path $PSScriptRoot 'common.ps1')
$acceptanceRoot = Join-Path $projectRoot '.local/http-acceptance'
[IO.Directory]::CreateDirectory($acceptanceRoot) | Out-Null
$runId = [guid]::NewGuid().ToString()
$fixturePath = Join-Path $acceptanceRoot "$runId.json"
$fixtureScript = Join-Path $projectRoot 'backend/tests/Support/parent-http-fixture.php'
$routerPath = Join-Path $projectRoot 'backend/tests/Support/parent-http-router.php'
$testEnvironment = @{
 APP_ENV='testing'; APP_DEBUG='false'; DB_CONNECTION='pgsql'; DB_DATABASE='familyguard_test'
 DB_HOST='127.0.0.1'; DB_PORT='5433'; DB_URL=''; CACHE_STORE='array'; SESSION_DRIVER='array'
 QUEUE_CONNECTION='sync'; MAIL_MAILER='array'; LOG_CHANNEL='stderr'; BCRYPT_ROUNDS='4'
 FAMILYGUARD_PARENT_HTTP='1'; FAMILYGUARD_PARENT_FIXTURE=$fixturePath;
 FAMILYGUARD_HTTP_ACCEPTANCE='1'; FAMILYGUARD_HTTP_FIXTURE=$fixturePath
 FAMILYGUARD_HTTP_PHP=$php; FAMILYGUARD_HTTP_FIXTURE_SCRIPT=$fixtureScript
}
$previous = @{}
$serverProcess = $null
$fixtureReady = $false
try {
 foreach($name in $testEnvironment.Keys) {
  $previous[$name] = [Environment]::GetEnvironmentVariable($name,'Process')
  [Environment]::SetEnvironmentVariable($name,$testEnvironment[$name],'Process')
 }
 & $php $fixtureScript prepare $fixturePath
 if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $fixturePath)) { throw "Synthetic fixture preparation failed (exit $LASTEXITCODE); HTTP tests were not started." }
 $fixtureReady = $true
 $listener = New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback,0)
 $listener.Start()
 $port = $listener.LocalEndpoint.Port
 $listener.Stop()
 $previous['FAMILYGUARD_PARENT_URL'] = $env:FAMILYGUARD_PARENT_URL
 $env:FAMILYGUARD_PARENT_URL = "http://127.0.0.1:$port"
 $previous['FAMILYGUARD_HTTP_URL'] = $env:FAMILYGUARD_HTTP_URL
 $env:FAMILYGUARD_HTTP_URL = "http://127.0.0.1:$port"
 $serverProcess = Start-Process -FilePath $php -ArgumentList @('-S',"127.0.0.1:$port",'-t',('"{0}"' -f (Join-Path $projectRoot 'backend/public')),('"{0}"' -f $routerPath)) -WorkingDirectory (Join-Path $projectRoot 'backend') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $acceptanceRoot "$runId.stdout.log") -RedirectStandardError (Join-Path $acceptanceRoot "$runId.stderr.log")
 $ready = $false
 for($attempt=0;$attempt -lt 20;$attempt++) {
  if($serverProcess.HasExited) { throw 'Isolated Laravel server exited; inspect local acceptance log.' }
  try {
   $health = Invoke-WebRequest "$env:FAMILYGUARD_HTTP_URL/api/v1/health" -UseBasicParsing -TimeoutSec 2
   if($health.StatusCode -eq 200) { $ready = $true; break }
  } catch { Start-Sleep -Milliseconds 500 }
 }
 if(-not $ready) { throw 'Isolated Laravel server did not become healthy.' }
 Push-Location (Join-Path $projectRoot 'frontend')
 try { & npm.cmd test -- --watch=false --include='**/parent-http.acceptance.spec.ts'; if ($LASTEXITCODE -ne 0) { throw "Laravel HTTP acceptance failed (exit $LASTEXITCODE)." } } finally { Pop-Location }
} finally {
 if($serverProcess -and -not $serverProcess.HasExited) { Stop-Process -Id $serverProcess.Id }
 try {
  if($fixtureReady) { & $php $fixtureScript cleanup $fixturePath; if ($LASTEXITCODE -ne 0) { throw "Synthetic fixture cleanup failed (exit $LASTEXITCODE); inspect local fixtures." } }
 } finally {
  foreach($name in $previous.Keys) { [Environment]::SetEnvironmentVariable($name,$previous[$name],'Process') }
 }
}
