<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PairingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_simultaneous_approvals_consume_one_invitation_only_once(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row-lock concurrency is verified on PostgreSQL only.');
        }
        $parent = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $parent->id, 'name' => 'Concurrent synthetic family']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        $code = strtoupper(bin2hex(random_bytes(16)));
        DB::table('device_pairings')->insert([
            'family_id' => $family, 'child_id' => $child, 'token_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $gate = tempnam(sys_get_temp_dir(), 'fg-pair-');
        unlink($gate);
        $config = config('database.connections.pgsql');
        $environment = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $config['database'],
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
            'PAIRING_CODE' => $code, 'PAIRING_GATE' => $gate,
        ];
        $workers = [];
        try {
            foreach (['a', 'b'] as $id) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/pairing-worker.php')], base_path(), $environment + ['PAIRING_WORKER' => $id]);
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            while (! file_exists($gate.'.a') || ! file_exists($gate.'.b')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Pairing workers did not become ready.');
                }
                usleep(10000);
            }
            touch($gate);
            $statuses = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), 'Pairing worker failed without disclosing its request or credential.');
                $statuses[] = (int) trim($worker->getOutput());
            }
            sort($statuses);
            $this->assertSame([201, 404], $statuses);
            $this->assertDatabaseCount('devices', 1);
            $this->assertNotNull(DB::table('device_pairings')->value('consumed_at'));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            foreach ([$gate, $gate.'.a', $gate.'.b'] as $file) {
                if (file_exists($file)) {
                    unlink($file);
                }
            }
        }
    }
}
