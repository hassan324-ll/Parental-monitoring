<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Tests\Support\HttpAcceptanceEnvironment;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
HttpAcceptanceEnvironment::guard($app);
// Same real HTTP kernel, routes and middleware. No test endpoints or injected parent identities.
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
