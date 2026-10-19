<?php

use App\Services\DeviceSharingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('familyguard:prune-sharing', function (DeviceSharingService $sharing) {
    $sharing->prune();
    $this->info('Sharing retention applied.');
})->purpose('Purge disabled/revoked sharing data and enforce usage/location retention');

// Disabled by default. The callback stays in the scheduler process so failures
// propagate, and overlapping scheduler ticks cannot start another cleanup.
Schedule::call(function (): void {
    if (Artisan::call('familyguard:prune-sharing') !== 0) {
        throw new RuntimeException('Sharing retention command failed.');
    }
})->name('familyguard-sharing-retention')->everyFiveMinutes()->timezone('UTC')
    ->withoutOverlapping(60)
    ->when(fn (): bool => config('familyguard.sharing_retention_enabled') === true);
