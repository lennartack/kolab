<?php

namespace Tests\Feature\Controller;

use App\Device;
use App\Package;
use App\Plan;
use App\SignupToken;
use App\Sku;
use App\User;
use Carbon\Carbon;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    protected $hash;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromDate(2025, 2, 2));

        $this->hash = str_repeat('1', 64);

        Device::query()->forceDelete();
        Plan::where('title', 'test')->delete();
        Package::where('title', 'test')->delete();
        User::where('role', User::ROLE_DEVICE)->forceDelete();
        $this->deleteTestUser('jane@kolabnow.com');
        SignupToken::query()->delete();
    }

    protected function tearDown(): void
    {
        SignupToken::query()->delete();
        Plan::where('title', 'test')->delete();
        Package::where('title', 'test')->delete();
        $this->deleteTestUser('jane@kolabnow.com');
        User::where('role', User::ROLE_DEVICE)->forceDelete();
        Device::query()->forceDelete();

        parent::tearDown();
    }

    /**
     * Test device info (GET /api/v4/device/<token>)
     */
    public function testInfo(): void
    {
        // Unknown hash (invalid token)
        $response = $this->get('api/v4/device/unknown');
        $response->assertStatus(404);

        // Unknown hash (valid token)
        $response = $this->get('api/v4/device/' . $this->hash);
        $response->assertStatus(404);

        // Getting info of a registered device
        [$device, $plan] = $this->initTestDevice();

        $response = $this->get('api/v4/device/' . $this->hash);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertStringContainsString('2025-02-02', $json['created_at']);
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

        [$device, $plan] = $this->initTestDevice();
        $user->setSetting('plan_id', $plan->id);

        $this->assertTrue($user->id != $device->account->id);

        // Claim an existing device
        $response = $this->actingAs($user)->post('api/v4/device/' . $this->hash . '/claim', []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('The device has been claimed successfully.', $json['message']);
        $this->assertCount(1, $device->entitlements);
        $this->assertSame($user->wallets()->first()->id, $device->entitlements[0]->wallet_id);

        // TODO: Test case when a claimant does not have a plan, or it does not include a device SKU
    }

    /**
     * Test device signup plans (GET /api/v4/device/<token>/plans)
     */
    public function testPlans(): void
    {
        // Unknown hash (valid token)
        $response = $this->get('api/v4/device/' . $this->hash);
        $response->assertStatus(404);

        $plan = Plan::create([
            'title' => 'test',
            'name' => 'Test',
            'description' => 'Test',
            'mode' => Plan::MODE_TOKEN,
        ]);

        SignupToken::create(['id' => $this->hash, 'plans' => [$plan->id]]);

        // Getting list of plans
        $response = $this->get("api/v4/device/{$this->hash}/plans");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame($plan->title, $json['list'][0]['title']);
        $this->assertSame(\config('app.currency'), $json['list'][0]['currency']);
    }

    /**
     * Test device registration (POST /api/v4/device/<token>/signup)
     */
    public function testSignup(): void
    {
        $sku = Sku::withEnvTenantContext()->where('title', 'device')->first();
        $plan = Plan::create([
            'title' => 'test',
            'name' => 'Test',
            'description' => 'Test',
            'mode' => Plan::MODE_TOKEN,
        ]);
        $package = Package::create([
            'title' => 'test',
            'name' => 'Device Account',
            'description' => 'A device account.',
            'discount_rate' => 0,
        ]);
        $plan->packages()->saveMany([$package]);
        $package->skus()->saveMany([$sku]);

        // Signup, missing plan
        $post = [];
        $response = $this->post("api/v4/device/{$this->hash}/signup", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame(['plan' => ['The plan field is required.']], $json['errors']);

        // Signup, invalid plan
        $post = ['plan' => 'invalid'];
        $response = $this->post("api/v4/device/{$this->hash}/signup", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame(['plan' => 'Invalid value'], $json['errors']);

        // Signup, valid plan, but unknown token
        $post = ['plan' => $plan->title];
        $response = $this->post("api/v4/device/{$this->hash}/signup", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame(['token' => ['The signup token is invalid.']], $json['errors']);

        SignupToken::create(['id' => $this->hash, 'plans' => [$plan->id]]);

        // Signup success
        $response = $this->post("api/v4/device/{$this->hash}/signup", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('bearer', $json['token_type']);
        $this->assertTrue(!empty($json['expires_in']) && is_int($json['expires_in']) && $json['expires_in'] > 0);
        $this->assertNotEmpty($json['access_token']);

        $device = Device::where('hash', $this->hash)->first();

        $this->assertTrue(!empty($device));
        $this->assertSame($device->account->email, $json['user']['email']);
        $this->assertSame(User::ROLE_DEVICE, $device->account->role);
        $this->assertSame($plan->id, $device->account->getSetting('plan_id'));
        $this->assertSame($this->hash, $device->account->getSetting('signup_token'));

        $entitlements = $device->wallet()->entitlements()->get();
        $this->assertCount(1, $entitlements);
        $this->assertSame($sku->id, $entitlements[0]->sku_id);
        $this->assertStringContainsString('2026-02-02', $entitlements[0]->updated_at->toDateString());
    }

    private function initTestDevice(): array
    {
        $sku = Sku::withEnvTenantContext()->where('title', 'device')->first();
        $plan = Plan::create([
            'title' => 'test',
            'name' => 'Test',
            'description' => 'Test',
            'mode' => Plan::MODE_TOKEN,
        ]);
        $package = Package::create([
            'title' => 'test',
            'name' => 'Device Account',
            'description' => 'A device account.',
            'discount_rate' => 0,
        ]);
        $plan->packages()->saveMany([$package]);
        $package->skus()->saveMany([$sku]);

        $device = Device::signup($this->hash, $plan, 'simple123');

        return [$device, $plan, $package, $sku];
    }
}
