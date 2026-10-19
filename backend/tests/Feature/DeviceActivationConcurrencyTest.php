<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeviceActivationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function competingOperations(): array
    {
        return [['revoke'], ['stop'], ['activate']];
    }

    #[DataProvider('competingOperations')]
    public function test_confirmation_serializes_with_replay_stop_and_revocation(string $other): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Activation races require PostgreSQL.');
        }
        $owner = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $owner->id, 'name' => 'Synthetic activation race family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        $device = Device::forceCreate(['family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic phone', 'credential_hash' => $hash]);
        DB::table('device_sharing_consents')->insert(['device_id' => $device->id, 'feature' => 'usage', 'enabled' => true, 'version' => 1,
            'disclosure_version' => 'sharing-v1', 'granted_at' => now('UTC')->subDays(2)]);
        $gate = tempnam(sys_get_temp_dir(), 'fg-activation-');
        unlink($gate);
        $c = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_DATABASE' => $c['database'],
            'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
            'ACTIVATION_GATE' => $gate, 'ACTIVATION_DEVICE' => (string) $device->id, 'ACTIVATION_HASH' => $hash,
            'ACTIVATION_TIME' => now('UTC')->startOfDay()->format('Y-m-d\TH:i:s\Z'), 'ACTIVATION_DAY' => now('UTC')->subDay()->toDateString()];
        $workers = [];
        try {
            foreach (['first' => 'activate', 'second' => $other] as $slot => $action) {
                $p = new Process([PHP_BINARY, base_path('tests/Support/activation-worker.php')], base_path(), $env + ['ACTIVATION_SLOT' => $slot, 'ACTIVATION_ACTION' => $action]);
                $p->setTimeout(30);
                $p->start();
                $workers[$slot] = $p;
            }
            $deadline = microtime(true) + 20;
            while (! file_exists($gate.'.first') || ! file_exists($gate.'.second')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Activation workers did not become ready.');
                }
                usleep(10000);
            }
            touch($gate);
            foreach ($workers as $p) {
                $this->assertSame(0, $p->wait(), 'Activation worker failed; inspect local logs without printing credentials.');
            }
            if ($other === 'activate') {
                $codes = [trim($workers['first']->getOutput()), trim($workers['second']->getOutput())];
                sort($codes);
                $this->assertSame(['200', '409'], $codes);
                $this->assertDatabaseHas('device_sharing_consents', ['device_id' => $device->id, 'feature' => 'usage', 'version' => 2, 'enabled' => true, 'disclosure_version' => 'usage-collection-v1']);
            } else {
                $this->assertSame('200', trim($workers['second']->getOutput()));
                $this->assertContains(trim($workers['first']->getOutput()), ['200', $other === 'revoke' ? '401' : '409']);
                $this->assertSame(0, DB::table('device_sharing_consents')->where('device_id', $device->id)->where('enabled', true)->count());
            }
        } finally {
            foreach ($workers as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
            foreach ([$gate, $gate.'.first', $gate.'.second'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
