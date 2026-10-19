<?php

use App\Services\DeviceCredentialService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\HttpAcceptanceEnvironment;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
HttpAcceptanceEnvironment::guard($app);
$action = $argv[1] ?? '';
$path = $argv[2] ?? '';
$expectedRoot = realpath(__DIR__.'/../../../.local/http-acceptance');
if (! $expectedRoot || realpath(dirname($path)) !== $expectedRoot || ! preg_match('/^[a-f0-9-]{36}\.json$/D', basename($path))) {
    throw new LogicException('Fixture path must be a unique file in the local acceptance directory.');
}
if ($action === 'prepare') {
    if (file_exists($path)) {
        throw new LogicException('Refusing to overwrite an acceptance fixture.');
    }
    // Only the dedicated test database passed the guard above. Never reset existing data.
    if (Artisan::call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Isolated test migrations failed.');
    }
    $fixture = DB::transaction(function (): array {
        $nonce = bin2hex(random_bytes(16));
        $email = 'fg-http-'.$nonce.'@example.invalid';
        $owner = DB::table('users')->insertGetId([
            'name' => 'HTTP synthetic parent', 'email' => $email, 'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT),
        ]);
        $family = DB::table('families')->insertGetId(['owner_id' => $owner, 'name' => 'HTTP synthetic family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'HTTP synthetic child']);
        $codes = [];
        foreach (['primary', 'revoked', 'expired'] as $kind) {
            $code = strtoupper(bin2hex(random_bytes(16)));
            DB::table('device_pairings')->insert([
                'family_id' => $family, 'child_id' => $child, 'token_hash' => hash('sha256', $code),
                'expires_at' => $kind === 'expired' ? now()->subMinute() : now()->addMinutes(10),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $codes[$kind] = $code;
        }

        return ['owner' => $owner, 'email' => $email, 'family' => $family, 'codes' => $codes];
    });
    if (file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        DB::transaction(function () use ($fixture): void {
            DB::table('families')->where('id', $fixture['family'])->where('owner_id', $fixture['owner'])->delete();
            DB::table('users')->where('id', $fixture['owner'])->where('email', $fixture['email'])->delete();
        });
        throw new RuntimeException('Could not write synthetic fixture.');
    }
    echo "Synthetic fixture prepared.\n";
} elseif (in_array($action, ['revoke', 'cleanup'], true)) {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (! preg_match('/^fg-http-[a-f0-9]{32}@example\.invalid$/D', $fixture['email']) ||
        ! DB::table('families')->where('id', $fixture['family'])->where('owner_id', $fixture['owner'])->exists() ||
        ! DB::table('users')->where('id', $fixture['owner'])->where('email', $fixture['email'])->exists()) {
        throw new LogicException('Synthetic fixture ownership could not be verified.');
    }
    if ($action === 'revoke') {
        foreach (DB::table('devices')->where('family_id', $fixture['family'])->pluck('id') as $device) {
            app(DeviceCredentialService::class)->revoke($device);
        }
        echo "Synthetic fixture devices revoked through credential service.\n";
    } else {
        DB::transaction(function () use ($fixture): void {
            DB::table('families')->where('id', $fixture['family'])->where('owner_id', $fixture['owner'])->delete();
            DB::table('users')->where('id', $fixture['owner'])->where('email', $fixture['email'])->delete();
        });
        if (DB::table('families')->where('id', $fixture['family'])->exists()) {
            throw new RuntimeException('Synthetic fixture cleanup failed.');
        }
        unlink($path);
        echo "Synthetic fixture removed.\n";
    }
} else {
    throw new LogicException('Unknown acceptance fixture action.');
}
