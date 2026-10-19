<?php

use App\Services\DeviceSharingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('familyguard:prune-sharing', function (DeviceSharingService $sharing) {
    $sharing->prune();
    $this->info('Sharing retention applied.');
})->purpose('Purge disabled/revoked sharing data and enforce usage/location retention');
