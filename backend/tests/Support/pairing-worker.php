<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = config('database.connections.pgsql.database');
if (config('database.default') !== 'pgsql' || ! preg_match('/^familyguard_test(?:_[a-z0-9]+)?$/D', $database)) {
    exit(2);
}
file_put_contents(getenv('PAIRING_GATE').'.'.getenv('PAIRING_WORKER'), 'ready');
$deadline = microtime(true) + 20;
while (! file_exists(getenv('PAIRING_GATE'))) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(10000);
}
$request = Request::create('/api/v1/device-pairings/approve', 'POST', [
    'code' => getenv('PAIRING_CODE'), 'approved' => true, 'device_name' => 'Concurrent synthetic phone',
], [], [], ['HTTP_ACCEPT' => 'application/json']);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle($request);
echo $response->getStatusCode();
$kernel->terminate($request, $response);
