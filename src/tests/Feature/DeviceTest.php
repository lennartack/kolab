<?php

namespace Tests\Feature;

use App\Device;
use App\Plan;
use App\SignupToken;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Plan::whereIn('title', ['device-test', 'device-test-default', 'user-test', 'user-test-default'])->delete();
        SignupToken::query()->delete();
    }

    protected function tearDown(): void
    {
        Plan::whereIn('title', ['device-test', 'device-test-default', 'user-test', 'user-test-default'])->delete();
        SignupToken::query()->delete();

        parent::tearDown();
    }

    /**
     * Test defaultPlan()
     */
    public function testDefaultPlan(): void
    {
        $plan_device = Plan::create([
            'title' => 'device-test',
            'name' => 'Test',
            'description' => 'Test',
            'mode' => Plan::MODE_TOKEN,
        ]);

        $plan_device_default = Plan::create([
            'title' => 'device-test-default',
            'name' => 'Test',
            'description' => 'Test',
            'mode' => Plan::MODE_TOKEN,
        ]);

        $plan_user = Plan::create([
            'title' => 'user-test',
            'name' => 'Test',
            'description' => 'Test',
            'mode' => Plan::MODE_TOKEN,
        ]);

        $plan_user_default = Plan::create([
            'title' => 'user-test-default',
            'name' => 'Test',
            'description' => 'Test',
            'mode' => Plan::MODE_TOKEN,
        ]);

        // Unknown token
        $this->assertNull(Device::defaultPlan('1234', true));
        $this->assertNull(Device::defaultPlan('1234', false));

        // Existing token, but with no plans
        $token = SignupToken::create(['id' => str_repeat('1', 64), 'plans' => []]);

        $this->assertNull(Device::defaultPlan($token->id, true));
        $this->assertNull(Device::defaultPlan($token->id, false));

        // A single non-default plan assigned
        $token->update(['plans' => [$plan_device->id]]);

        // @phpstan-ignore-next-line
        $this->assertSame($plan_device->id, Device::defaultPlan($token->id, true)->id);
        $this->assertNull(Device::defaultPlan($token->id, false));

        // An existing default plan, no user- plan yet
        $token->update(['plans' => [$plan_device->id, $plan_device_default->id]]);

        // @phpstan-ignore-next-line
        $this->assertSame($plan_device_default->id, Device::defaultPlan($token->id, true)->id);
        $this->assertNull(Device::defaultPlan($token->id, false));

        // Add user plan
        $token->update(['plans' => [$plan_device->id, $plan_device_default->id, $plan_user->id]]);

        // @phpstan-ignore-next-line
        $this->assertSame($plan_device_default->id, Device::defaultPlan($token->id, true)->id);
        // @phpstan-ignore-next-line
        $this->assertSame($plan_user->id, Device::defaultPlan($token->id, false)->id);

        // Add user default plan
        $token->update(['plans' => [$plan_device->id, $plan_device_default->id, $plan_user->id, $plan_user_default->id]]);

        // @phpstan-ignore-next-line
        $this->assertSame($plan_device_default->id, Device::defaultPlan($token->id, true)->id);
        // @phpstan-ignore-next-line
        $this->assertSame($plan_user_default->id, Device::defaultPlan($token->id, false)->id);
    }
}
