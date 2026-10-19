<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\HttpAcceptanceEnvironment;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
HttpAcceptanceEnvironment::guard($app);
if (getenv('FAMILYGUARD_PARENT_HTTP') !== '1') {
    throw new LogicException('Parent HTTP harness requires explicit opt-in.');
}
$path = getenv('FAMILYGUARD_PARENT_FIXTURE');
$root = realpath(__DIR__.'/../../../.local/http-acceptance');
if (! $root || realpath(dirname($path)) !== $root || ! preg_match('/^[a-f0-9-]{36}\.json$/D', basename($path))) {
    throw new LogicException('Invalid parent fixture path.');
}
$fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
config(['services.firebase.project_id' => 'synthetic-parent-http']);
// Test certificate transport only. Production verifier/resolver/guard remain real.
Http::preventStrayRequests();
Http::fake(['https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com' => Http::response(['synthetic-parent-key' => $fixture['certificate']], 200, ['Cache-Control' => 'public, max-age=600'])]);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
