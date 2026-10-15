<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FamilyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_cannot_reference_child_from_another_family(): void
    {
        $user = DB::table('users')->insertGetId(['name' => 'Synthetic Parent', 'email' => 'synthetic@example.test', 'password' => bcrypt('synthetic-test-only')]);
        $family = DB::table('families')->insertGetId(['name' => 'Synthetic A', 'owner_id' => $user]);
        $other = DB::table('families')->insertGetId(['name' => 'Synthetic B', 'owner_id' => $user]);
        $child = DB::table('children')->insertGetId(['display_name' => 'Synthetic Child', 'family_id' => $family]);
        $this->expectException(QueryException::class);
        DB::table('devices')->insert(['family_id' => $other, 'child_id' => $child, 'public_id' => '00000000-0000-4000-8000-000000000001', 'name' => 'Synthetic Device']);
    }
}
