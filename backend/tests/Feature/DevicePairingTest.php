<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevicePairingTest extends TestCase
{
    use RefreshDatabase;

    private function familyAndChild(User $parent): array
    {
        $this->actingAs($parent, 'firebase-parent');
        $family = $this->postJson('/api/v1/families', ['name' => 'Synthetic Family'])
            ->assertCreated()->json('data.id');
        $child = $this->postJson("/api/v1/families/{$family}/children", [
            'display_name' => 'Synthetic Child',
        ])->assertCreated()->json('data.id');
        return [$family, $child];
    }

    public function test_pairing_needs_parent_authentication_and_valid_child_membership(): void
    {
        $this->postJson('/api/v1/families/1/children/1/pairings')->assertUnauthorized();
        [$family, $child] = $this->familyAndChild(User::factory()->create());
        $code = $this->postJson("/api/v1/families/{$family}/children/{$child}/pairings")
            ->assertCreated()->json('code');
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $code);
        $this->assertDatabaseMissing('device_pairings', ['token_hash' => $code]);

        $this->actingAs(User::factory()->create(), 'firebase-parent');
        $this->postJson("/api/v1/families/{$family}/children/{$child}/pairings")->assertNotFound();
    }

    public function test_explicit_approval_issues_device_token_once_and_supports_revocation(): void
    {
        [$family, $child] = $this->familyAndChild(User::factory()->create());
        $code = $this->postJson("/api/v1/families/{$family}/children/{$child}/pairings")
            ->assertCreated()->json('code');

        $this->postJson('/api/v1/device-pairings/preview', ['code' => $code])
            ->assertOk()->assertJsonPath('child_name', 'Synthetic Child')
            ->assertJsonPath('requires_approval', true);
        $this->postJson('/api/v1/device-pairings/approve', [
            'code' => $code, 'approved' => false, 'device_name' => 'Test Phone',
        ])->assertUnprocessable();

        $response = $this->postJson('/api/v1/device-pairings/approve', [
            'code' => $code, 'approved' => true,
            'device_name' => 'Test Phone', 'android_version' => '15',
        ])->assertCreated();
        $token = $response->json('device_token');
        $publicId = $response->json('device.id');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);

        $this->postJson('/api/v1/device-pairings/approve', [
            'code' => $code, 'approved' => true, 'device_name' => 'Replay Phone',
        ])->assertNotFound();

        $this->withToken($token)->getJson('/api/v1/companion/me')
            ->assertOk()->assertJsonPath('paired', true);
        $this->withToken($token)->getJson('/api/v1/companion/me')
            ->assertDontSee($token);

        $this->getJson("/api/v1/families/{$family}/devices")->assertOk()->assertJsonCount(1, 'data')
            ->assertDontSee($token);

        $this->deleteJson("/api/v1/families/{$family}/devices/{$publicId}")
            ->assertOk()->assertJsonPath('message', 'Device access revoked.');
        $this->withToken($token)->getJson('/api/v1/companion/me')->assertUnauthorized();
    }

    public function test_expired_pairing_cannot_be_previewed_or_used(): void
    {
        [$family, $child] = $this->familyAndChild(User::factory()->create());
        $code = $this->postJson("/api/v1/families/{$family}/children/{$child}/pairings")
            ->assertCreated()->json('code');
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/device-pairings/preview', ['code' => $code])->assertNotFound();
        $this->postJson('/api/v1/device-pairings/approve', [
            'code' => $code, 'approved' => true, 'device_name' => 'Expired Phone',
        ])->assertNotFound();
    }

    public function test_device_may_disconnect_itself(): void
    {
        [$family, $child] = $this->familyAndChild(User::factory()->create());
        $code = $this->postJson("/api/v1/families/{$family}/children/{$child}/pairings")
            ->assertCreated()->json('code');
        $token = $this->postJson('/api/v1/device-pairings/approve', [
            'code' => $code, 'approved' => true, 'device_name' => 'Test Phone',
        ])->assertCreated()->json('device_token');
        $this->withToken($token)->postJson('/api/v1/companion/disconnect')->assertOk();
        $this->withToken($token)->getJson('/api/v1/companion/me')->assertUnauthorized();
    }
}
