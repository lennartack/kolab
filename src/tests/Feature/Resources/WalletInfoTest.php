<?php

namespace Tests\Feature\Resources;

use App\Discount;
use App\Http\Resources\WalletInfoResource;
use App\Plan;
use Carbon\Carbon;
use Tests\TestCase;

class WalletInfoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('wallets-controller@kolabnow.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('wallets-controller@kolabnow.com');

        parent::tearDown();
    }

    /**
     * Test for getWalletNotice() method
     */
    public function testGetWalletNotice(): void
    {
        $user = $this->getTestUser('wallets-controller@kolabnow.com');
        $plan = Plan::withObjectTenantContext($user)->where('title', 'individual')->first();
        $user->assignPlan($plan);
        $wallet = $user->wallets()->first();

        $resource = new WalletInfoResource($wallet);
        $method = new \ReflectionMethod($resource, 'getWalletNotice');

        // User/entitlements created today, balance=0
        $notice = $method->invoke($resource);

        $this->assertSame('You are in your free trial period.', $notice);

        $wallet->owner->created_at = Carbon::now()->subWeeks(3);
        $wallet->owner->save();

        $notice = $method->invoke($resource);

        $this->assertSame('Your free trial is about to end, top up to continue.', $notice);

        // User/entitlements created today, balance=-10 CHF
        $wallet->balance = -1000;
        $notice = $method->invoke($resource);

        $this->assertSame('You are out of credit, top up your balance now.', $notice);

        // User/entitlements created slightly more than a month ago, balance=9,99 CHF (monthly)
        $this->backdateEntitlements($wallet->entitlements, Carbon::now()->subMonthsWithoutOverflow(1)->subDays(1));
        $wallet->refresh();

        // test "1 month"
        $wallet->balance = 990;
        $notice = $method->invoke($resource);

        $this->assertMatchesRegularExpression('/\((1 month|4 weeks|3 weeks)\)/', $notice);

        // test "2 months"
        $wallet->balance = 990 * 2.6;
        $notice = $method->invoke($resource);

        $this->assertMatchesRegularExpression('/\(1 month 4 weeks\)/', $notice);

        // Change locale to make sure the text is localized by Carbon
        \app()->setLocale('de');

        // test "almost 2 years"
        $wallet->balance = 990 * 23.5;
        $notice = $method->invoke($resource);

        $this->assertMatchesRegularExpression('/\(1 Jahr 10 Monate\)/', $notice);

        // Old entitlements, 100% discount
        $this->backdateEntitlements($wallet->entitlements, Carbon::now()->subDays(40));
        $discount = Discount::withObjectTenantContext($user)->where('discount', 100)->first();
        $wallet->discount()->associate($discount);
        $wallet->refresh();

        $notice = $method->invoke($resource);

        $this->assertNull($notice);
    }
}
