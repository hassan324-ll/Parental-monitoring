<?php

use App\Services\DeviceCredentialService;
use App\Services\DeviceSharingActivationService;
use App\Services\DeviceSharingService;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\HttpAcceptanceEnvironment;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
HttpAcceptanceEnvironment::guard($app);
config(['familyguard.device_activation_enabled' => true]);
file_put_contents(getenv('ACTIVATION_GATE').'.'.getenv('ACTIVATION_SLOT'), 'ready');
$deadline = microtime(true) + 20;
while (! file_exists(getenv('ACTIVATION_GATE'))) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(10000);
}
try {
    $id = (int) getenv('ACTIVATION_DEVICE');
    $hash = getenv('ACTIVATION_HASH');
    if (getenv('ACTIVATION_ACTION') === 'revoke') {
        app(DeviceCredentialService::class)->revoke($id);
    } elseif (getenv('ACTIVATION_ACTION') === 'stop') {
        app(DeviceSharingService::class)->decide($id, $hash, 'usage', false);
    } else {
        app(DeviceSharingActivationService::class)->confirm($id, $hash, 'usage', ['acknowledged' => true, 'expected_version' => 1, 'disclosure_version' => 'usage-collection-v1']);
    }
    echo '200';
} catch (HttpException $e) {
    echo $e->getStatusCode();
}
