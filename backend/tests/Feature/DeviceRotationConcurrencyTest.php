<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeviceRotationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Rotation row-lock concurrency requires isolated PostgreSQL.');
        }
    }

    private function fixture(): array
    {
        $parent = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $parent->id, 'name' => 'Concurrent rotation family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $token = bin2hex(random_bytes(32));
        $device = Device::forceCreate([
            'family_id' => $family, 'child_id' => $child, 'public_id' => (string) Str::uuid(),
            'name' => 'Synthetic phone', 'credential_hash' => hash('sha256', $token),
        ]);
        DB::table('device_credential_history')->insert([
            'device_id' => $device->id, 'credential_hash' => hash('sha256', $token), 'created_at' => now(),
        ]);

        return [$device, $token];
    }

    private function runWorkers(array $actions): array
    {
        $gate = tempnam(sys_get_temp_dir(), 'fg-rotate-');
        unlink($gate);
        $config = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $config['database'],
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr', 'ROTATION_GATE' => $gate,
        ];
        $workers = [];
        try {
            foreach ($actions as $id => $action) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/rotation-worker.php')], base_path(),
                    $environment + ['ROTATION_WORKER' => (string) $id] + $action);
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            foreach (array_keys($actions) as $id) {
                while (! file_exists($gate.'.'.$id)) {
                    if (microtime(true) > $deadline) {
                        $this->fail('Rotation workers did not become ready. No credentials disclosed.');
                    }
                    usleep(10000);
                }
            }
            touch($gate);
            $statuses = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), 'Rotation worker failed; request and credential output suppressed.');
                $statuses[] = (int) trim($worker->getOutput());
            }

            return $statuses;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach (array_merge([$gate], array_map(fn ($id) => $gate.'.'.$id, array_keys($actions))) as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_same_credential_can_only_win_one_simultaneous_rotation(): void
    {
        [$device, $token] = $this->fixture();
        $nextA = bin2hex(random_bytes(32));
        $nextB = bin2hex(random_bytes(32));
        $statuses = $this->runWorkers([
            ['ROTATION_TOKEN' => $token, 'ROTATION_NEXT_HASH' => hash('sha256', $nextA)],
            ['ROTATION_TOKEN' => $token, 'ROTATION_NEXT_HASH' => hash('sha256', $nextB)],
        ]);
        sort($statuses);
        $this->assertSame([200, 401], $statuses);
        $this->assertContains($device->fresh()->credential_hash, [hash('sha256', $nextA), hash('sha256', $nextB)]);
        $this->assertDatabaseCount('device_credential_history', 2);
        $this->withToken($token)->getJson('/api/v1/companion/me')->assertUnauthorized();
    }

    public function test_parent_revocation_cannot_be_undone_by_concurrent_rotation(): void
    {
        [$device, $token] = $this->fixture();
        $next = bin2hex(random_bytes(32));
        $statuses = $this->runWorkers([
            ['ROTATION_TOKEN' => $token, 'ROTATION_NEXT_HASH' => hash('sha256', $next)],
            ['ROTATION_ACTION' => 'parent-revoke', 'ROTATION_DEVICE_ID' => (string) $device->id],
        ]);
        $this->assertContains($statuses[0], [200, 401]);
        $this->assertSame(200, $statuses[1]);
        $this->assertNull($device->fresh()->credential_hash);
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->withToken($token)->getJson('/api/v1/companion/me')->assertUnauthorized();
        $this->withToken($next)->getJson('/api/v1/companion/me')->assertUnauthorized();
    }

    public function test_two_devices_cannot_claim_the_same_replacement_hash(): void
    {
        [, $a] = $this->fixture();
        [, $b] = $this->fixture();
        $next = bin2hex(random_bytes(32));
        $statuses = $this->runWorkers([
            ['ROTATION_TOKEN' => $a, 'ROTATION_NEXT_HASH' => hash('sha256', $next)],
            ['ROTATION_TOKEN' => $b, 'ROTATION_NEXT_HASH' => hash('sha256', $next)],
        ]);
        sort($statuses);
        $this->assertSame([200, 422], $statuses);
        $this->assertSame(1, Device::where('credential_hash', hash('sha256', $next))->count());
        $this->assertDatabaseCount('device_credential_history', 3);
    }
}
