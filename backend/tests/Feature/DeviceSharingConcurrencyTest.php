<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeviceSharingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_revocation_dominates_simultaneous_device_opt_in(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Device lock race requires PostgreSQL.');
        }
        $owner = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $owner->id, 'name' => 'Synthetic race family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        $device = Device::forceCreate(['family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic phone', 'credential_hash' => $hash]);
        DB::table('app_usage_daily')->insert(['device_id' => $device->id, 'day' => now()->toDateString(), 'package_name' => 'example.synthetic', 'duration_seconds' => 1]);
        $gate = tempnam(sys_get_temp_dir(), 'fg-sharing-');
        unlink($gate);
        $c = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_DATABASE' => $c['database'],
            'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
            'SHARING_GATE' => $gate, 'SHARING_DEVICE' => (string) $device->id, 'SHARING_HASH' => $hash];
        $workers = [];
        try {
            foreach (['enable', 'revoke'] as $action) {
                $p = new Process([PHP_BINARY, base_path('tests/Support/sharing-worker.php')], base_path(), $env + ['SHARING_ACTION' => $action]);
                $p->setTimeout(30);
                $p->start();
                $workers[$action] = $p;
            }
            $deadline = microtime(true) + 20;
            while (! file_exists($gate.'.enable') || ! file_exists($gate.'.revoke')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Sharing workers did not become ready.');
                }usleep(10000);
            }
            touch($gate);
            foreach ($workers as $p) {
                $this->assertSame(0, $p->wait(), 'Sharing worker failed without disclosing synthetic credentials.');
            }
            $this->assertSame('200', trim($workers['revoke']->getOutput()));
            $this->assertContains(trim($workers['enable']->getOutput()), ['200', '401']);
            $this->assertNotNull($device->fresh()->revoked_at);
            $this->assertNull($device->fresh()->credential_hash);
            $this->assertSame(0, DB::table('device_sharing_consents')->where('device_id', $device->id)->where('enabled', true)->count());
            $this->assertDatabaseMissing('app_usage_daily', ['device_id' => $device->id]);
        } finally {
            foreach ($workers as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            foreach ([$gate, $gate.'.enable', $gate.'.revoke'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
