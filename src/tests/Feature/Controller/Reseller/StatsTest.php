<?php

namespace Tests\Feature\Controller\Reseller;

use App\Discount;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useResellerUrl();

        DB::table('wallets')->update(['discount_id' => null]);
    }

    protected function tearDown(): void
    {
        DB::table('wallets')->update(['discount_id' => null]);

        parent::tearDown();
    }

    /**
     * Test charts (GET /api/v4/stats/chart/<chart>)
     */
    public function testChart(): void
    {
        $user = $this->getTestUser('john@kolab.org');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $reseller = $this->getTestUser('reseller@' . \config('app.domain'));

        // Unauth access
        $response = $this->get("api/v4/stats/chart/discounts");
        $response->assertStatus(401);

        // Normal user
        $response = $this->actingAs($user)->get("api/v4/stats/chart/discounts");
        $response->assertStatus(403);

        // Admin user
        $response = $this->actingAs($admin)->get("api/v4/stats/chart/discounts");
        $response->assertStatus(403);

        // Unknown chart name
        $response = $this->actingAs($reseller)->get("api/v4/stats/chart/unknown");
        $response->assertStatus(404);

        // 'income' chart
        $response = $this->actingAs($reseller)->get("api/v4/stats/chart/income");
        $response->assertStatus(404);

        // 'discounts' chart
        $response = $this->actingAs($reseller)->get("api/v4/stats/chart/discounts");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('Discounts', $json['title']);
        $this->assertSame('donut', $json['type']);
        $this->assertSame([], $json['data']['labels']);
        $this->assertSame([['values' => []]], $json['data']['datasets']);

        // 'users' chart
        $response = $this->actingAs($reseller)->get("api/v4/stats/chart/users");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('Users - last 8 weeks', $json['title']);
        $this->assertCount(8, $json['data']['labels']);
        $this->assertSame(date('Y-W'), $json['data']['labels'][7]);
        $this->assertCount(2, $json['data']['datasets']);
        $this->assertSame('Created', $json['data']['datasets'][0]['name']);
        $this->assertSame('Deleted', $json['data']['datasets'][1]['name']);

        // 'users-all' chart
        $response = $this->actingAs($reseller)->get("api/v4/stats/chart/users-all");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('All Users - last year', $json['title']);
        $this->assertCount(54, $json['data']['labels']);
        $this->assertCount(2, $json['data']['datasets']);

        // 'vouchers' chart
        $discount = Discount::withObjectTenantContext($user)->where('code', 'TEST')->first();
        $wallet = $user->wallets->first();
        $wallet->discount()->associate($discount);
        $wallet->save();

        $response = $this->actingAs($reseller)->get("api/v4/stats/chart/vouchers");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('Vouchers', $json['title']);
        $this->assertSame(['TEST'], $json['data']['labels']);
        $this->assertSame([['values' => [1]]], $json['data']['datasets']);
    }
}
