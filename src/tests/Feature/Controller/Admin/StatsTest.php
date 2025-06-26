<?php

namespace Tests\Feature\Controller\Admin;

use App\Discount;
use App\Http\Controllers\API\V4\Admin\StatsController;
use App\Payment;
use App\Utils;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useAdminUrl();

        Payment::query()->delete();
        DB::table('wallets')->update(['discount_id' => null]);

        $this->deleteTestUser('test-stats@' . \config('app.domain'));
    }

    protected function tearDown(): void
    {
        Payment::query()->delete();
        DB::table('wallets')->update(['discount_id' => null]);

        $this->deleteTestUser('test-stats@' . \config('app.domain'));

        parent::tearDown();
    }

    /**
     * Test charts (GET /api/v4/stats/chart/<chart>)
     */
    public function testChart(): void
    {
        $user = $this->getTestUser('john@kolab.org');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');

        // Non-admin user
        $response = $this->actingAs($user)->get("api/v4/stats/chart/discounts");
        $response->assertStatus(403);

        // Unknown chart name
        $response = $this->actingAs($admin)->get("api/v4/stats/chart/unknown");
        $response->assertStatus(404);

        // 'discounts' chart
        $response = $this->actingAs($admin)->get("api/v4/stats/chart/discounts");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('Discounts', $json['title']);
        $this->assertSame('donut', $json['type']);
        $this->assertSame([], $json['data']['labels']);
        $this->assertSame([['values' => []]], $json['data']['datasets']);

        // 'income' chart
        $response = $this->actingAs($admin)->get("api/v4/stats/chart/income");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('Income in CHF - last 8 weeks', $json['title']);
        $this->assertSame('bar', $json['type']);
        $this->assertCount(8, $json['data']['labels']);
        $this->assertSame(date('Y-W'), $json['data']['labels'][7]);
        $this->assertSame([['values' => [0, 0, 0, 0, 0, 0, 0, 0]]], $json['data']['datasets']);

        // 'users' chart
        $response = $this->actingAs($admin)->get("api/v4/stats/chart/users");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('Users - last 8 weeks', $json['title']);
        $this->assertCount(8, $json['data']['labels']);
        $this->assertSame(date('Y-W'), $json['data']['labels'][7]);
        $this->assertCount(2, $json['data']['datasets']);
        $this->assertSame('Created', $json['data']['datasets'][0]['name']);
        $this->assertSame('Deleted', $json['data']['datasets'][1]['name']);

        // 'users-all' chart
        $response = $this->actingAs($admin)->get("api/v4/stats/chart/users-all");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('All Users - last year', $json['title']);
        $this->assertCount(54, $json['data']['labels']);
        $this->assertCount(2, $json['data']['datasets']);
        $this->assertSame('All', $json['data']['datasets'][0]['name']);
        $this->assertSame('Suspended', $json['data']['datasets'][1]['name']);
        $this->assertCount(54, $json['data']['datasets'][0]['values']);
        $this->assertCount(54, $json['data']['datasets'][1]['values']);

        // 'users-per-country' chart
        $response = $this->actingAs($admin)->get("api/v4/stats/chart/users-per-country");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('Users per country', $json['title']);
        $this->assertSame('donut', $json['type']);
        $this->assertContains('Switzerland', $json['data']['labels']);
        $this->assertContains('United States', $json['data']['labels']);
        $this->assertCount(1, $json['data']['datasets']);

        // 'vouchers' chart
        $discount = Discount::withObjectTenantContext($user)->where('code', 'TEST')->first();
        $wallet = $user->wallets->first();
        $wallet->discount()->associate($discount);
        $wallet->save();

        $response = $this->actingAs($admin)->get("api/v4/stats/chart/vouchers");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('Vouchers', $json['title']);
        $this->assertSame(['TEST'], $json['data']['labels']);
        $this->assertSame([['values' => [1]]], $json['data']['datasets']);
    }

    /**
     * Test income chart currency handling
     */
    public function testChartIncomeCurrency(): void
    {
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $john = $this->getTestUser('john@kolab.org');
        $user = $this->getTestUser('test-stats@' . \config('app.domain'));
        $wallet = $user->wallets()->first();
        $wallet->currency = 'EUR';
        $wallet->save();
        $johns_wallet = $john->wallets()->first();

        // Create some test payments
        Payment::create([
            'id' => 'test1',
            'description' => '',
            'status' => Payment::STATUS_PAID,
            'amount' => 1000,
            'credit_amount' => 1000,
            'type' => Payment::TYPE_ONEOFF,
            'wallet_id' => $wallet->id,
            'provider' => 'mollie',
            'currency' => 'EUR',
            'currency_amount' => 1000,
        ]);
        Payment::create([
            'id' => 'test2',
            'description' => '',
            'status' => Payment::STATUS_PAID,
            'amount' => 2000,
            'credit_amount' => 2000,
            'type' => Payment::TYPE_RECURRING,
            'wallet_id' => $wallet->id,
            'provider' => 'mollie',
            'currency' => 'EUR',
            'currency_amount' => 2000,
        ]);
        Payment::create([
            'id' => 'test3',
            'description' => '',
            'status' => Payment::STATUS_PAID,
            'amount' => 3000,
            'credit_amount' => 3000,
            'type' => Payment::TYPE_ONEOFF,
            'wallet_id' => $johns_wallet->id,
            'provider' => 'mollie',
            'currency' => 'EUR',
            'currency_amount' => 2800,
        ]);
        Payment::create([
            'id' => 'test4',
            'description' => '',
            'status' => Payment::STATUS_PAID,
            'amount' => 4000,
            'credit_amount' => 4000,
            'type' => Payment::TYPE_RECURRING,
            'wallet_id' => $johns_wallet->id,
            'provider' => 'mollie',
            'currency' => 'CHF',
            'currency_amount' => 4000,
        ]);
        Payment::create([
            'id' => 'test5',
            'description' => '',
            'status' => Payment::STATUS_OPEN,
            'amount' => 5000,
            'credit_amount' => 5000,
            'type' => Payment::TYPE_ONEOFF,
            'wallet_id' => $johns_wallet->id,
            'provider' => 'mollie',
            'currency' => 'CHF',
            'currency_amount' => 5000,
        ]);
        Payment::create([
            'id' => 'test6',
            'description' => '',
            'status' => Payment::STATUS_FAILED,
            'amount' => 6000,
            'credit_amount' => 6000,
            'type' => Payment::TYPE_ONEOFF,
            'wallet_id' => $johns_wallet->id,
            'provider' => 'mollie',
            'currency' => 'CHF',
            'currency_amount' => 6000,
        ]);

        // 'income' chart
        $response = $this->actingAs($admin)->get("api/v4/stats/chart/income");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('Income in CHF - last 8 weeks', $json['title']);
        $this->assertSame('bar', $json['type']);
        $this->assertCount(8, $json['data']['labels']);
        $this->assertSame(date('Y-W'), $json['data']['labels'][7]);

        // 7000 CHF + 3000 EUR =
        $expected = 7000 + (int) round(3000 * Utils::exchangeRate('EUR', 'CHF'));

        $this->assertCount(1, $json['data']['datasets']);
        $this->assertSame($expected / 100, $json['data']['datasets'][0]['values'][7]);
    }

    /**
     * Test payers chart
     */
    public function testChartPayers(): void
    {
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');

        DB::table('stats')->truncate();

        $response = $this->actingAs($admin)->get("api/v4/stats/chart/payers");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('Payers - last year', $json['title']);
        $this->assertSame('line', $json['type']);
        $this->assertCount(54, $json['data']['labels']);
        $this->assertSame(date('Y-W'), $json['data']['labels'][53]);
        $this->assertCount(1, $json['data']['datasets']);
        $this->assertCount(54, $json['data']['datasets'][0]['values']);

        DB::table('stats')->insert([
            'type' => StatsController::TYPE_PAYERS,
            'value' => 5,
            'created_at' => \now(),
        ]);
        DB::table('stats')->insert([
            'type' => StatsController::TYPE_PAYERS,
            'value' => 7,
            'created_at' => \now(),
        ]);

        $response = $this->actingAs($admin)->get("api/v4/stats/chart/payers");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(6, $json['data']['datasets'][0]['values'][53]);
    }
}
