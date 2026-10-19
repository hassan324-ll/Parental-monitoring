<?php

use App\Services\DeviceCredentialService;
use App\Services\DeviceSharingService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\HttpAcceptanceEnvironment;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
HttpAcceptanceEnvironment::guard($app);
file_put_contents(getenv('SHARING_GATE').'.'.getenv('SHARING_ACTION'), 'ready');
$deadline = microtime(true) + 20;
while (! file_exists(getenv('SHARING_GATE'))) {
    if (microtime(true) > $deadline) {
        exit(3);
    }usleep(10000);
}
try {
    if (getenv('SHARING_ACTION') === 'revoke') {
        app(DeviceCredentialService::class)->revoke((int) getenv('SHARING_DEVICE'));
    } else {
        app(DeviceSharingService::class)->decide((int) getenv('SHARING_DEVICE'), getenv('SHARING_HASH'), 'usage', true, 'sharing-v1');
    }
    echo '200';
} catch (HttpException $e) {
    echo $e->getStatusCode();
}
