<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_foundation_tables_exist(): void
    {
        foreach (['users', 'families', 'family_members', 'children', 'devices', 'device_pairings', 'permission_grants', 'app_usage_daily', 'location_updates', 'screen_share_sessions', 'alerts', 'audit_logs'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }
}
