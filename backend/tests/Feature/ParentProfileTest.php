<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_profile_requires_firebase_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->withToken('synthetic-invalid-token')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_authenticated_parent_receives_only_minimal_profile(): void
    {
        $parent = User::factory()->create(['name' => 'Synthetic Parent']);
        $this->actingAs($parent, 'firebase-parent');

        $this->getJson('/api/v1/me')->assertOk()->assertExactJson([
            'data' => [
                'id' => $parent->id,
                'name' => 'Synthetic Parent',
                'email' => $parent->email,
            ],
        ]);
    }
}
