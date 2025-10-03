<?php

namespace Tests\Feature\Controller\Reseller;

use App\Sku;
use Tests\TestCase;

class SkusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useResellerUrl();

        Sku::where('title', 'test')->delete();

        $this->clearBetaEntitlements();
    }

    protected function tearDown(): void
    {
        Sku::where('title', 'test')->delete();

        $this->clearBetaEntitlements();

        parent::tearDown();
    }

    /**
     * Test fetching SKUs list for a domain (GET /domains/<id>/skus)
     */
    public function testDomainSkus(): void
    {
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $user = $this->getTestUser('john@kolab.org');
        $domain = $this->getTestDomain('kolab.org');

        // Unauth access not allowed
        $response = $this->get("api/v4/domains/{$domain->id}/skus");
        $response->assertStatus(401);

        // User access not allowed
        $response = $this->actingAs($user)->get("api/v4/domains/{$domain->id}/skus");
        $response->assertStatus(403);

        // Admin access not allowed
        $response = $this->actingAs($admin)->get("api/v4/domains/{$domain->id}/skus");
        $response->assertStatus(403);

        // Reseller from another tenant
        $response = $this->actingAs($reseller2)->get("api/v4/domains/{$domain->id}/skus");
        $response->assertStatus(404);

        // Reseller access
        $response = $this->actingAs($reseller1)->get("api/v4/domains/{$domain->id}/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(1, $json['list']);
        // Note: Details are tested where we test API\V4\SkusController
    }

    /**
     * Test fetching SKUs list
     */
    public function testIndex(): void
    {
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $user = $this->getTestUser('john@kolab.org');
        $sku = Sku::withEnvTenantContext()->where('title', 'mailbox')->first();

        // Unauth access not allowed
        $response = $this->get("api/v4/skus");
        $response->assertStatus(401);

        // User access not allowed
        $response = $this->actingAs($user)->get("api/v4/skus");
        $response->assertStatus(403);

        // Admin access not allowed
        $response = $this->actingAs($admin)->get("api/v4/skus");
        $response->assertStatus(403);

        $response = $this->actingAs($reseller1)->get("api/v4/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(12, $json['list']);

        $this->assertSame(100, $json['list'][0]['prio']);
        $this->assertSame($sku->id, $json['list'][0]['id']);
        $this->assertSame($sku->title, $json['list'][0]['title']);
        $this->assertSame($sku->name, $json['list'][0]['name']);
        $this->assertSame($sku->description, $json['list'][0]['description']);
        $this->assertSame($sku->cost, $json['list'][0]['cost']);
        $this->assertSame($sku->units_free, $json['list'][0]['units_free']);
        $this->assertSame($sku->period, $json['list'][0]['period']);
        $this->assertSame($sku->active, $json['list'][0]['active']);
        $this->assertSame('user', $json['list'][0]['type']);
        $this->assertSame('Mailbox', $json['list'][0]['handler']);

        // Test with another tenant
        $sku = Sku::where('title', 'mailbox')->where('tenant_id', $reseller2->tenant_id)->first();
        $response = $this->actingAs($reseller2)->get("api/v4/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(6, $json['list']);

        $this->assertSame(100, $json['list'][0]['prio']);
        $this->assertSame($sku->id, $json['list'][0]['id']);
        $this->assertSame($sku->title, $json['list'][0]['title']);
        $this->assertSame($sku->name, $json['list'][0]['name']);
        $this->assertSame($sku->description, $json['list'][0]['description']);
        $this->assertSame($sku->cost, $json['list'][0]['cost']);
        $this->assertSame($sku->units_free, $json['list'][0]['units_free']);
        $this->assertSame($sku->period, $json['list'][0]['period']);
        $this->assertSame($sku->active, $json['list'][0]['active']);
        $this->assertSame('user', $json['list'][0]['type']);
        $this->assertSame('Mailbox', $json['list'][0]['handler']);
    }

    /**
     * Test fetching SKUs list for a user (GET /users/<id>/skus)
     */
    public function testUserSkus(): void
    {
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $user = $this->getTestUser('john@kolab.org');

        // Unauth access not allowed
        $response = $this->get("api/v4/users/{$user->id}/skus");
        $response->assertStatus(401);

        // User access not allowed
        $response = $this->actingAs($user)->get("api/v4/users/{$user->id}/skus");
        $response->assertStatus(403);

        // Admin access not allowed
        $response = $this->actingAs($admin)->get("api/v4/users/{$user->id}/skus");
        $response->assertStatus(403);

        // Reseller from another tenant
        $response = $this->actingAs($reseller2)->get("api/v4/users/{$user->id}/skus");
        $response->assertStatus(404);

        // Reseller access
        $response = $this->actingAs($reseller1)->get("api/v4/users/{$user->id}/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json['list']);
        // Note: Details are tested where we test API\V4\SkusController
    }
}
