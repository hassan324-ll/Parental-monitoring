<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigrationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_migrations_can_roll_back_and_run_again(): void
    {
        $this->artisan('migrate:reset', ['--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('families'));
        $this->assertFalse(Schema::hasTable('devices'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('users', 'firebase_uid'));
        $this->assertTrue(Schema::hasTable('screen_share_sessions'));
    }

    public function test_uid_is_unique_but_multiple_unlinked_users_are_allowed(): void
    {
        User::factory()->count(2)->create(['firebase_uid' => null]);
        User::factory()->create(['firebase_uid' => 'unique-synthetic-uid']);
        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['firebase_uid' => 'unique-synthetic-uid']);
    }

    public function test_family_membership_is_unique(): void
    {
        $parent = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $parent->id, 'name' => 'Synthetic']);
        DB::table('family_members')->insert(['family_id' => $family, 'user_id' => $parent->id, 'role' => 'owner']);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('family_members')->insert(['family_id' => $family, 'user_id' => $parent->id, 'role' => 'viewer']);
    }

    public function test_child_deletion_cascades_to_its_devices(): void
    {
        $parent = User::factory()->create();
        $family = DB::table('families')->insertGetId(['owner_id' => $parent->id, 'name' => 'Synthetic']);
        $child = DB::table('children')->insertGetId(['family_id' => $family, 'display_name' => 'Synthetic child']);
        DB::table('devices')->insert(['family_id' => $family, 'child_id' => $child, 'public_id' => '00000000-0000-4000-8000-000000000003', 'name' => 'Synthetic device']);
        DB::table('children')->where('id', $child)->delete();
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_family_scoped_queries_have_leading_family_indexes(): void
    {
        foreach (['children', 'devices'] as $table) {
            $indexes = Schema::getIndexes($table);
            $this->assertNotEmpty(array_filter($indexes, fn (array $index) => ($index['columns'][0] ?? null) === 'family_id'), $table);
        }
    }
}
