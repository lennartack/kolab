<?php

namespace Tests\Feature\Controller;

use App\Device;
use App\Plan;
use App\SignupToken;
use App\Sku;
use App\User;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    protected $hash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hash = str_repeat('1', 64);

        SignupToken::query()->delete();
        Device::query()->forceDelete();
        User::where('role', User::ROLE_DEVICE)->forceDelete();
        $this->deleteTestUser('jane@kolabnow.com');
    }

    protected function tearDown(): void
    {
        SignupToken::query()->delete();
        Device::query()->forceDelete();
        User::where('role', User::ROLE_DEVICE)->forceDelete();
        $this->deleteTestUser('jane@kolabnow.com');

        parent::tearDown();
    }

    /**
     * Test device registration and info (GET /api/v4/device/<hash>)
     */
    public function testInfo(): void
    {
        // Unknown hash (invalid hash)
        $response = $this->get('api/v4/device/unknown');
        $response->assertStatus(404);

        // Unknown hash (valid hash)
        $response = $this->get('api/v4/device/' . $this->hash);
        $response->assertStatus(404);

        $sku = Sku::withEnvTenantContext()->where('title', 'device')->first();
        $plan = Plan::withEnvTenantContext()->where('title', 'individual')->first();
        $plan->signupTokens()->create(['id' => $this->hash]);

        // First registration
        $response = $this->get('api/v4/device/' . $this->hash);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertStringContainsString(date('Y-m-d'), $json['created_at']);
        $this->assertSame([], $json['plans']);

        $device = Device::where('hash', $this->hash)->first();
        $this->assertTrue(!empty($device));
        $entitlements = $device->wallet()->entitlements()->get();
        $this->assertCount(1, $entitlements);
        $this->assertSame($sku->id, $entitlements[0]->sku_id);

        // Getting info of a registered device
        $response = $this->get('api/v4/device/' . $this->hash);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertStringContainsString(date('Y-m-d'), $json['created_at']);
    }

    /**
     * Test claiming a device (POST /api/v4/device/<hash>/claim)
     */
    public function testClaim(): void
    {
        // Unauthenticated
        $response = $this->post('api/v4/device/unknown/claim');
        $response->assertStatus(401);

        $user = $this->getTestUser('jane@kolabnow.com');

        // Unknown device hash
        $response = $this->actingAs($user)->post('api/v4/device/' . $this->hash . '/claim', []);
        $response->assertStatus(404);

        $device = Device::register($this->hash);

        // Claim an existing device
        $response = $this->actingAs($user)->post('api/v4/device/' . $this->hash . '/claim', []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('The device has been claimed successfully.', $json['message']);
        $this->assertCount(1, $device->entitlements);
        $this->assertSame($user->wallets()->first()->id, $device->entitlements[0]->wallet_id);
    }
}
