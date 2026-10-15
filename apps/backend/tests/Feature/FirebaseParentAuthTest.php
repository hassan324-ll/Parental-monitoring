<?php

namespace Tests\Feature;

use App\Auth\FirebaseTokenVerifier;
use App\Auth\VerifiedFirebaseParent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_unique_email_race_fails_closed_without_aborting_the_connection(): void
    {
        $mock = Mockery::mock(FirebaseTokenVerifier::class);
        $mock->shouldReceive('verify')->once()->andReturn(new VerifiedFirebaseParent('racing-uid', 'racing@example.test', 'Synthetic Race'));
        $this->app->instance(FirebaseTokenVerifier::class, $mock);
        User::creating(function (User $user): void {
            if ($user->firebase_uid === 'racing-uid') {
                // Reproduce a uniqueness collision after the resolver's email lookup.
                DB::table('users')->insert(['name' => 'Competing synthetic account', 'email' => 'racing@example.test', 'password' => 'synthetic-unusable-password']);
            }
        });
        $this->withToken('race-fixture')->getJson('/api/v1/families')->assertUnauthorized();
        $this->assertDatabaseMissing('users', ['firebase_uid' => 'racing-uid']);
        $this->assertIsInt(User::query()->count());
    }

    public function test_existing_uid_does_not_relink_to_a_different_email_account(): void
    {
        $existing = User::factory()->create(['firebase_uid' => 'stable-uid', 'email' => 'original@example.test']);
        $other = User::factory()->create(['email' => 'changed@example.test']);
        $mock = Mockery::mock(FirebaseTokenVerifier::class);
        $mock->shouldReceive('verify')->once()->andReturn(new VerifiedFirebaseParent('stable-uid', 'changed@example.test', 'Synthetic'));
        $this->app->instance(FirebaseTokenVerifier::class, $mock);
        $this->withToken('uid-fixture')->postJson('/api/v1/families', ['name' => 'Stable identity'])->assertCreated()->assertJsonPath('data.owner_id', $existing->id);
        $this->assertDatabaseHas('users', ['id' => $other->id, 'firebase_uid' => null]);
    }

    public function test_invalid_authentication_is_rate_limited_before_token_verification(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->withToken('invalid-fixture')->getJson('/api/v1/families')->assertUnauthorized();
        }
        $this->withToken('invalid-fixture')->getJson('/api/v1/families')->assertStatus(429);
    }
}
