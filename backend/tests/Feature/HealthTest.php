<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_returns_only_status(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_cors_allows_only_dashboard(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:4200'])->getJson('/api/v1/health')->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
        $this->withHeaders(['Origin' => 'https://untrusted.example'])->getJson('/api/v1/health')->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4200');
    }
}
