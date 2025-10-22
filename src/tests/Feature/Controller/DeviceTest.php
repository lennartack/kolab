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
        Plan::whereIn('title', ['device-test', 'device-test-default'])->delete();
        Package::where('title', 'test')->delete();
        User::where('role', User::ROLE_DEVICE)->forceDelete();
        $this->deleteTestUser('jane@kolabnow.com');
        SignupToken::query()->delete();
    }

    protected function tearDown(): void
    {
        SignupToken::query()->delete();
        Plan::whereIn('title', ['device-test', 'device-test-default'])->delete();
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
        $this->assertSame(12, $json['freeMonths']);

        // Assert freeMonths after 2 months
        Carbon::setTestNow(Carbon::createFromDate(2025, 4, 2));
        $response = $this->get('api/v4/device/' . $this->hash);
        $json = $response->json();

        $this->assertSame(10, $json['freeMonths']);

        // Assert freeMonths after 12 months
        Carbon::setTestNow(Carbon::createFromDate(2026, 2, 2));
        $response = $this->get('api/v4/device/' . $this->hash);
        $json = $response->json();

        $this->assertSame(0, $json['freeMonths']);

        // Assert freeMonths after 13 months
        Carbon::setTestNow(Carbon::createFromDate(2026, 3, 2));
        $response = $this->get('api/v4/device/' . $this->hash);
        $json = $response->json();

        $this->assertSame(0, $json['freeMonths']);
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
        $this->assertStringContainsString('2025-02-02', $json['device']['created_at']);
        $this->assertSame(12, $json['device']['freeMonths']);
        $device = Device::where('hash', $this->hash)->first();
        $this->assertCount(1, $device->entitlements);
        $this->assertSame($user->wallets()->first()->id, $device->entitlements[0]->wallet_id);

        // Claim a soft-deleted device, no token registered
        $device->delete();
        $response = $this->actingAs($user)->post('api/v4/device/' . $this->hash . '/claim', []);
        $response->assertStatus(404);

        // Claim a soft-deleted device, token registered
        SignupToken::create(['id' => $this->hash, 'plans' => [$plan->id]]);
        $response = $this->actingAs($user)->post('api/v4/device/' . $this->hash . '/claim', []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('The device has been claimed successfully.', $json['message']);
        $device = Device::where('hash', $this->hash)->first();
        $this->assertCount(1, $device->entitlements);
        $this->assertSame($user->wallets()->first()->id, $device->entitlements[0]->wallet_id);

        // Claim a non-existing device
        $device->forceDelete();
        $response = $this->actingAs($user)->post('api/v4/device/' . $this->hash . '/claim', []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('The device has been claimed successfully.', $json['message']);
        $device = Device::where('hash', $this->hash)->first();
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
            'title' => 'device-test',
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
            'title' => 'device-test-default',
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
        $this->assertStringContainsString('2025-02-02', $json['device']['created_at']);
        $this->assertSame(12, $json['device']['freeMonths']);

        $device = Device::where('hash', $this->hash)->first();
        $account = $device->account;

        $this->assertTrue(!empty($device));
        $this->assertSame($account->email, $json['user']['email']);
        $this->assertSame(User::ROLE_DEVICE, $account->role);
        $this->assertSame($plan->id, $account->getSetting('plan_id'));
        $this->assertSame($this->hash, $account->getSetting('signup_token'));

        $entitlements = $device->wallet()->entitlements()->get();
        $this->assertCount(1, $entitlements);
        $this->assertSame($sku->id, $entitlements[0]->sku_id);
        $this->assertStringContainsString('2026-02-02', $entitlements[0]->updated_at->toDateString());

        $device->created_at = \now()->subMonthsWithoutOverflow();
        $device->save();

        // Note: without this finding the proper wallet may not work because of how Device::wallet() works
        Carbon::setTestNow(Carbon::createFromDate(2025, 3, 4));

        // Signup again (w/o a plan now)
        unset($post['plan']);
        $response = $this->post("api/v4/device/{$this->hash}/signup", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('bearer', $json['token_type']);
        $this->assertTrue(!empty($json['expires_in']) && is_int($json['expires_in']) && $json['expires_in'] > 0);
        $this->assertNotEmpty($json['access_token']);

        $device = Device::where('hash', $this->hash)->first();

        $this->assertTrue($device->account->id != $account->id);
        $this->assertSame($device->account->email, $json['user']['email']);
        $this->assertSame(User::ROLE_DEVICE, $device->account->role);
        $this->assertSame($plan->id, $device->account->getSetting('plan_id'));
        $this->assertSame($this->hash, $device->account->getSetting('signup_token'));

        $entitlements = $device->wallet()->entitlements()->get();
        $this->assertCount(1, $entitlements);
        $this->assertSame($sku->id, $entitlements[0]->sku_id);
        $this->assertStringContainsString('2025-03-04', $entitlements[0]->created_at->toDateString());
        $this->assertStringContainsString('2026-01-02', $entitlements[0]->updated_at->toDateString());
    }

    /**
     * Test unclaiming a device (POST /api/v4/device/<hash>/unclaim)
     */
    public function testUnclaim(): void
    {
        $user = $this->getTestUser('jane@kolabnow.com');

        // Unauthenticated
        $response = $this->post('api/v4/device/unknown/unclaim');
        $response->assertStatus(401);

        // Unknown device hash
        $response = $this->actingAs($user)->post('api/v4/device/unknown/unclaim', []);
        $response->assertStatus(404);

        [$device] = $this->initTestDevice();

        $this->assertTrue($user->id != $device->account->id);
        $this->assertCount(1, $device->entitlements);

        // Unclaim an existing device owned by another user
        $response = $this->actingAs($user)->post('api/v4/device/' . $this->hash . '/unclaim', []);
        $response->assertStatus(403);

        // Unclaim an existing device
        $response = $this->actingAs($device->account)->post('api/v4/device/' . $this->hash . '/unclaim', []);
        $response->assertStatus(200);

        $json = $response->json();

        $device->refresh();
        $this->assertSame('success', $json['status']);
        $this->assertSame('The device has been unclaimed successfully.', $json['message']);
        $this->assertTrue($device->trashed());
        $this->assertCount(0, $device->entitlements);
    }

    private function initTestDevice(): array
    {
        $sku = Sku::withEnvTenantContext()->where('title', 'device')->first();
        $plan = Plan::create([
            'title' => 'device-test',
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
