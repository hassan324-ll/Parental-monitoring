<?php

namespace Tests\Feature;

use App\Auth\FirebaseTokenVerifier;
use App\Auth\VerifiedFirebaseParent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FirebaseParentAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_bearer_token_cannot_access_family_api(): void
    {
        $this->withToken('definitely-not-a-jwt')->getJson('/api/v1/families')->assertUnauthorized();
    }

    public function test_verified_provider_uid_creates_local_user_and_can_create_family(): void
    {
        $mock = Mockery::mock(FirebaseTokenVerifier::class);
        $mock->shouldReceive('verify')->once()->with('verified-fixture')
            ->andReturn(new VerifiedFirebaseParent('firebase-test-parent-1', 'synthetic-parent@example.test', 'Synthetic Parent'));
        $this->app->instance(FirebaseTokenVerifier::class, $mock);

        $this->withToken('verified-fixture')->postJson('/api/v1/families', ['name' => 'Synthetic Family'])
            ->assertCreated()->assertJsonPath('data.name', 'Synthetic Family');

        $this->assertDatabaseHas('users', [
            'firebase_uid' => 'firebase-test-parent-1',
            'email' => 'synthetic-parent@example.test',
        ]);
    }

    public function test_email_collision_is_not_silently_linked_to_another_user(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        $mock = Mockery::mock(FirebaseTokenVerifier::class);
        $mock->shouldReceive('verify')->once()->with('verified-fixture')
            ->andReturn(new VerifiedFirebaseParent('different-uid', 'existing@example.test', 'Other Person'));
        $this->app->instance(FirebaseTokenVerifier::class, $mock);

        $this->withToken('verified-fixture')->getJson('/api/v1/families')->assertUnauthorized();
        $this->assertDatabaseMissing('users', ['firebase_uid' => 'different-uid']);
    }
}
