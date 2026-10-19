<?php

use App\Services\DeviceCredentialService;
use App\Services\DeviceSharingService;
use App\Services\UsageIngestionService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\HttpAcceptanceEnvironment;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
HttpAcceptanceEnvironment::guard($app);
file_put_contents(getenv('USAGE_GATE').'.'.getenv('USAGE_SLOT'), 'ready');
$deadline = microtime(true) + 20;
while (! file_exists(getenv('USAGE_GATE'))) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(10000);
}
try {
    $id = (int) getenv('USAGE_DEVICE');
    $hash = getenv('USAGE_HASH');
    if (getenv('USAGE_ACTION') === 'revoke') {
        app(DeviceCredentialService::class)->revoke($id);
    } elseif (getenv('USAGE_ACTION') === 'stop') {
        app(DeviceSharingService::class)->decide($id, $hash, 'usage', false);
    } else {
        app(UsageIngestionService::class)->store($id, $hash, ['consent_version' => 3,
            'day' => getenv('USAGE_DAY'), 'recorded_until' => getenv('USAGE_TIME'), 'apps' => [['package_name' => 'example.synthetic', 'duration_seconds' => 60]]]);
    }
    echo '200';
} catch (HttpException $e) {
    echo $e->getStatusCode();
}
