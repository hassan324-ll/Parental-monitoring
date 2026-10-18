<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function test_pairing_devices_are_isolated_from_other_families_and_viewers_cannot_manage(): void
    {
        $parent = User::factory()->create();
        [$family, $child] = $this->familyAndChild($parent);
        $code = $this->postJson("/api/v1/families/{$family}/children/{$child}/pairings")->assertCreated()->json('code');
        $result = $this->postJson('/api/v1/device-pairings/approve', [
            'code' => $code, 'approved' => true, 'device_name' => 'Synthetic phone',
        ])->assertCreated();
        $id = $result->json('device.id');
        $token = $result->json('device_token');
        $this->assertDatabaseHas('devices', ['public_id' => $id, 'credential_hash' => hash('sha256', $token)]);

        $this->actingAs(User::factory()->create(), 'firebase-parent');
        $this->getJson("/api/v1/families/{$family}/devices")->assertNotFound();
        $this->deleteJson("/api/v1/families/{$family}/devices/{$id}")->assertNotFound();

        $viewer = User::factory()->create();
        DB::table('family_members')->insert(['family_id' => $family, 'user_id' => $viewer->id, 'role' => 'viewer']);
        $this->actingAs($viewer, 'firebase-parent');
        $this->getJson("/api/v1/families/{$family}/devices")->assertOk()->assertDontSee('credential_hash')->assertDontSee($token);
        $this->postJson("/api/v1/families/{$family}/children/{$child}/pairings")->assertForbidden();
        $this->deleteJson("/api/v1/families/{$family}/devices/{$id}")->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/companion/me')->assertOk();
    }

    public function test_invalid_codes_missing_approval_and_expiration_boundary_do_not_create_devices(): void
    {
        $this->postJson('/api/v1/device-pairings/preview', ['code' => 'not-a-code'])->assertUnprocessable();
        [$family, $child] = $this->familyAndChild(User::factory()->create());
        $invite = $this->postJson("/api/v1/families/{$family}/children/{$child}/pairings")->assertCreated()->json();
        $this->postJson('/api/v1/device-pairings/approve', ['code' => $invite['code'], 'device_name' => 'Synthetic phone'])->assertUnprocessable();
        $this->postJson('/api/v1/device-pairings/preview', ['code' => strtolower($invite['code'])])->assertOk();
        $this->travelTo(Carbon::parse($invite['expires_at']));
        $this->postJson('/api/v1/device-pairings/preview', ['code' => $invite['code']])->assertNotFound();
        $this->postJson('/api/v1/device-pairings/approve', ['code' => $invite['code'], 'approved' => true, 'device_name' => 'Synthetic phone'])->assertNotFound();
        $this->assertDatabaseCount('devices', 0);
    }

    public function test_pairing_public_endpoints_are_rate_limited_and_device_tokens_are_separate(): void
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/v1/device-pairings/preview', ['code' => str_repeat('0', 32)])->assertNotFound();
        }
        $this->postJson('/api/v1/device-pairings/preview', ['code' => str_repeat('0', 32)])->assertTooManyRequests();
        $this->getJson('/api/v1/companion/me')->assertUnauthorized();
        $this->withToken('synthetic.firebase.token')->getJson('/api/v1/companion/me')->assertUnauthorized();
        $this->withToken(str_repeat('a', 64))->getJson('/api/v1/companion/me')->assertUnauthorized();
    }
}
