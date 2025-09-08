<?php

namespace Tests\Feature\Controller;

use App\Http\Controllers\API\V4\SkusController;
use App\Package;
use App\Sku;
use App\Tenant;
use Tests\TestCase;

class SkusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('jane@kolabnow.com');
        $this->clearBetaEntitlements();
        Sku::where('title', 'test')->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('jane@kolabnow.com');
        $this->clearBetaEntitlements();
        Sku::where('title', 'test')->delete();

        parent::tearDown();
    }

    /**
     * Test fetching SKUs list
     */
    public function testIndex(): void
    {
        // Unauth access not allowed
        $response = $this->get("api/v4/skus");
        $response->assertStatus(401);

        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $sku = Sku::withEnvTenantContext()->where('title', 'mailbox')->first();

        // Create an sku for another tenant, to make sure it is not included in the result
        $nsku = Sku::create([
            'title' => 'test',
            'name' => 'Test',
            'description' => '',
            'active' => true,
            'cost' => 100,
            'handler_class' => 'App\Handlers\Mailbox',
        ]);
        $tenant = Tenant::whereNotIn('id', [\config('app.tenant_id')])->first();
        $nsku->tenant_id = $tenant->id;
        $nsku->save();

        $response = $this->actingAs($john)->get("api/v4/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(12, $json);

        $this->assertSame(100, $json[0]['prio']);
        $this->assertSame($sku->id, $json[0]['id']);
        $this->assertSame($sku->title, $json[0]['title']);
        $this->assertSame($sku->name, $json[0]['name']);
        $this->assertSame($sku->description, $json[0]['description']);
        $this->assertSame($sku->cost, $json[0]['cost']);
        $this->assertSame($sku->units_free, $json[0]['units_free']);
        $this->assertSame($sku->period, $json[0]['period']);
        $this->assertSame($sku->active, $json[0]['active']);
        $this->assertSame('user', $json[0]['type']);
        $this->assertSame('Mailbox', $json[0]['handler']);

        // Test the type filter, and nextCost property (user with one domain)
        $response = $this->actingAs($john)->get("api/v4/skus?type=domain");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(1, $json);
        $this->assertSame('domain-hosting', $json[0]['title']);
        $this->assertSame(100, $json[0]['nextCost']); // second domain costs 100

        // Test the type filter, and nextCost property (user with no domain)
        $jane = $this->getTestUser('jane@kolabnow.com');
        $kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $jane->assignPackage($kolab);

        $response = $this->actingAs($jane)->get("api/v4/skus?type=domain");

        $json = $response->json();

        $this->assertCount(1, $json);
        $this->assertSame('domain-hosting', $json[0]['title']);
        $this->assertSame(0, $json[0]['nextCost']); // first domain costs 0
    }

    /**
     * Test updateEntitlements() method
     */
    public function testUpdateEntitlements(): void
    {
        $jane = $this->getTestUser('jane@kolabnow.com');
        $wallet = $jane->wallets()->first();
        $mailbox_sku = Sku::withEnvTenantContext()->where('title', 'mailbox')->first();
        $storage_sku = Sku::withEnvTenantContext()->where('title', 'storage')->first();

        // Invalid empty input
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid quantity of mailboxes');
        SkusController::updateEntitlements($jane, [], $wallet);

        $this->assertSame(0, $wallet->entitlements()->count());

        // Add mailbox SKU
        SkusController::updateEntitlements($jane, [$mailbox_sku->id => 1], $wallet);

        $this->assertSame(1, $wallet->entitlements()->count());
        $this->assertSame($mailbox_sku->id, $wallet->entitlements()->first()->sku_id);

        // Add 2 storage SKUs
        $skus = [$mailbox_sku->id => 1, $storage_sku->id => 2];
        SkusController::updateEntitlements($jane, $skus, $wallet);

        $this->assertSame(1, $wallet->entitlements()->where('sku_id', $mailbox_sku->id)->count());
        $this->assertSame(2, $wallet->entitlements()->where('sku_id', $storage_sku->id)->count());

        // Add two more storage SKUs
        $skus = [$mailbox_sku->id => 1, $storage_sku->id => 7];
        SkusController::updateEntitlements($jane, $skus, $wallet);

        $this->assertSame(1, $wallet->entitlements()->where('sku_id', $mailbox_sku->id)->count());
        $this->assertSame(7, $wallet->entitlements()->where('sku_id', $storage_sku->id)->count());

        // Remove two storage SKUs
        $skus = [$mailbox_sku->id => 1, $storage_sku->id => 3];
        SkusController::updateEntitlements($jane, $skus, $wallet);

        $this->assertSame(1, $wallet->entitlements()->where('sku_id', $mailbox_sku->id)->count());
        // Note: 5 not 4 because of free_units=5
        $this->assertSame(5, $wallet->entitlements()->where('sku_id', $storage_sku->id)->count());

        // Request SKU that can't be assigned to a User object
        // Such SKUs are being ignored silently
        $group_sku = Sku::withEnvTenantContext()->where('title', 'group')->first();
        $skus = [$mailbox_sku->id => 1, $storage_sku->id => 5, $group_sku->id => 1];
        SkusController::updateEntitlements($jane, $skus, $wallet);

        $this->assertSame(0, $wallet->entitlements()->where('sku_id', $group_sku->id)->count());

        // Error - add extra mailbox SKU
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid quantity of mailboxes');

        $skus = [$mailbox_sku->id => 2, $storage_sku->id => 5];
        SkusController::updateEntitlements($jane, $skus, $wallet);

        // Error - disabled subscriptions
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Subscriptions disabled');

        \config(['app.with_subscriptions' => false]);
        $skus = [$mailbox_sku->id => 1];
        SkusController::updateEntitlements($jane, $skus, $wallet);
    }
}
