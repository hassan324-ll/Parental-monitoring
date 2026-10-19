<?php

use App\Services\DeviceCredentialService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.pgsql.database');
if (config('app.env') !== 'testing' || config('database.default') !== 'pgsql'
    || ! preg_match('/^familyguard_test(?:_[a-z0-9]+)?$/D', $database)) {
    exit(2);
}
file_put_contents(getenv('ROTATION_GATE').'.'.getenv('ROTATION_WORKER'), 'ready');
$deadline = microtime(true) + 20;
while (! file_exists(getenv('ROTATION_GATE'))) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(10000);
}
if (getenv('ROTATION_ACTION') === 'parent-revoke') {
    $app->make(DeviceCredentialService::class)->revoke((int) getenv('ROTATION_DEVICE_ID'));
    echo 200;
    exit(0);
}
$request = Request::create('/api/v1/companion/rotate', 'POST', [
    'next_credential_hash' => getenv('ROTATION_NEXT_HASH'),
], [], [], [
    'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.getenv('ROTATION_TOKEN'),
]);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
