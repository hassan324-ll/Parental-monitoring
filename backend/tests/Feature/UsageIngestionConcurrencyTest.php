<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\UsageIngestionService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UsageIngestionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function competingOperations(): array
    {
        return [['revoke'], ['stop'], ['ingest']];
    }

    #[DataProvider('competingOperations')]
    public function test_device_lock_prevents_duplicate_or_resurrected_data(string $other): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Usage lock races require PostgreSQL.');
        }
        $owner = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $owner->id, 'name' => 'Synthetic usage race family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $hash = hash('sha256', bin2hex(random_bytes(32)));
        $device = Device::forceCreate(['family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic phone', 'credential_hash' => $hash]);
        DB::table('device_sharing_consents')->insert(['device_id' => $device->id, 'feature' => 'usage', 'enabled' => true, 'version' => 3,
            'disclosure_version' => UsageIngestionService::ACTIVATION_DISCLOSURE, 'granted_at' => now('UTC')->subDays(2)]);
        $gate = tempnam(sys_get_temp_dir(), 'fg-usage-');
        unlink($gate);
        $c = config('database.connections.pgsql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_DATABASE' => $c['database'],
            'DB_USERNAME' => $c['username'], 'DB_PASSWORD' => $c['password'], 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
            'USAGE_GATE' => $gate, 'USAGE_DEVICE' => (string) $device->id, 'USAGE_HASH' => $hash,
            'USAGE_TIME' => now('UTC')->startOfDay()->format('Y-m-d\TH:i:s\Z'), 'USAGE_DAY' => now('UTC')->subDay()->toDateString()];
        $workers = [];
        try {
            foreach (['first' => 'ingest', 'second' => $other] as $slot => $action) {
                $p = new Process([PHP_BINARY, base_path('tests/Support/usage-worker.php')], base_path(), $env + ['USAGE_SLOT' => $slot, 'USAGE_ACTION' => $action]);
                $p->setTimeout(30);
                $p->start();
                $workers[$slot] = $p;
            }
            $deadline = microtime(true) + 20;
            while (! file_exists($gate.'.first') || ! file_exists($gate.'.second')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Usage workers did not become ready.');
                }
                usleep(10000);
            }
            touch($gate);
            foreach ($workers as $p) {
                $this->assertSame(0, $p->wait(), 'Usage worker failed; inspect local logs without printing credentials.');
            }
            $this->assertSame('200', trim($workers['second']->getOutput()));
            if ($other === 'ingest') {
                $this->assertSame('200', trim($workers['first']->getOutput()));
                $this->assertDatabaseCount('app_usage_daily', 1);
                $this->assertSame(60, (int) DB::table('app_usage_daily')->sum('duration_seconds'));
                $this->assertDatabaseCount('usage_ingestion_snapshots', 1);
            } else {
                $this->assertContains(trim($workers['first']->getOutput()), ['200', $other === 'revoke' ? '401' : '403']);
                $this->assertDatabaseMissing('app_usage_daily', ['device_id' => $device->id]);
                $this->assertDatabaseMissing('usage_ingestion_snapshots', ['device_id' => $device->id]);
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
