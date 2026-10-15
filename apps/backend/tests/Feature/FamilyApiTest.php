<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/families')->assertUnauthorized();
        $this->postJson('/api/v1/families', ['name' => 'Home'])->assertUnauthorized();
        $this->getJson('/api/v1/families/1/children')->assertUnauthorized();
        $this->postJson('/api/v1/families/1/children', ['display_name' => 'Test'])->assertUnauthorized();
    }

    public function test_parent_can_create_a_family_and_child(): void
    {
        $parent = User::factory()->create();
        $this->actingAs($parent);

        $response = $this->postJson('/api/v1/families', ['name' => 'Test family'])
            ->assertCreated()->assertJsonPath('data.name', 'Test family');
        $familyId = $response->json('data.id');

        $this->assertDatabaseHas('families', ['id' => $familyId, 'owner_id' => $parent->id]);
        $this->assertDatabaseHas('family_members', [
            'family_id' => $familyId, 'user_id' => $parent->id, 'role' => 'owner',
        ]);

        $this->postJson("/api/v1/families/{$familyId}/children", ['display_name' => 'Test child'])
            ->assertCreated()->assertJsonPath('data.family_id', $familyId)
            ->assertJsonPath('data.display_name', 'Test child');

        $this->getJson("/api/v1/families/{$familyId}/children")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/families')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $familyId);
    }

    public function test_another_parent_cannot_access_a_family(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($owner);
        $familyId = $this->postJson('/api/v1/families', ['name' => 'Private family'])
            ->assertCreated()->json('data.id');

        $this->actingAs($stranger);
        $this->getJson('/api/v1/families')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/families/{$familyId}/children")->assertNotFound();
        $this->postJson("/api/v1/families/{$familyId}/children", ['display_name' => 'Wrong'])
            ->assertNotFound();
    }

    public function test_viewer_can_read_but_cannot_add_children(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();

        $this->actingAs($owner);
        $familyId = $this->postJson('/api/v1/families', ['name' => 'View only'])
            ->assertCreated()->json('data.id');

        Family::findOrFail($familyId)->members()->attach($viewer->id, ['role' => 'viewer']);

        $this->actingAs($viewer);
        $this->getJson('/api/v1/families')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/families/{$familyId}/children")->assertOk();
        $this->postJson("/api/v1/families/{$familyId}/children", ['display_name' => 'Not allowed'])
            ->assertForbidden();
    }

    public function test_invalid_names_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/api/v1/families', ['name' => ''])->assertUnprocessable();
        $familyId = $this->postJson('/api/v1/families', ['name' => 'Valid family'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/families/{$familyId}/children", ['display_name' => ''])
            ->assertUnprocessable();
    }
}
