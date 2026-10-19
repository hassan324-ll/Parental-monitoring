<?php

use App\Services\DeviceCredentialService;
use App\Services\DeviceSharingService;
use App\Services\LocationIngestionService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\HttpAcceptanceEnvironment;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
HttpAcceptanceEnvironment::guard($app);
file_put_contents(getenv('LOCATION_GATE').'.'.getenv('LOCATION_SLOT'), 'ready');
$deadline = microtime(true) + 20;
while (! file_exists(getenv('LOCATION_GATE'))) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(10000);
}
try {
    $id = (int) getenv('LOCATION_DEVICE');
    $hash = getenv('LOCATION_HASH');
    if (getenv('LOCATION_ACTION') === 'revoke') {
        app(DeviceCredentialService::class)->revoke($id);
    } elseif (getenv('LOCATION_ACTION') === 'stop') {
        app(DeviceSharingService::class)->decide($id, $hash, 'location', false);
    } else {
        app(LocationIngestionService::class)->store($id, $hash, ['consent_version' => 3,
            'recorded_at' => getenv('LOCATION_TIME'), 'latitude' => 0, 'longitude' => 0, 'accuracy_meters' => 1000]);
    }
    echo '200';
} catch (HttpException $e) {
    echo $e->getStatusCode();
}
