<?php

namespace Tests\Feature\Controller\Admin;

use App\Sku;
use Tests\TestCase;

class SkusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useAdminUrl();

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
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $user = $this->getTestUser('john@kolab.org');
        $domain = $this->getTestDOmain('kolab.org');

        // Unauth access not allowed
        $response = $this->get("api/v4/domains/{$domain->id}/skus");
        $response->assertStatus(401);

        // Non-admin access not allowed
        $response = $this->actingAs($user)->get("api/v4/domains/{$domain->id}/skus");
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->get("api/v4/domains/{$domain->id}/skus");
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
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $user = $this->getTestUser('john@kolab.org');
        $sku = Sku::withEnvTenantContext()->where('title', 'mailbox')->first();

        // Unauth access not allowed
        $response = $this->get("api/v4/skus");
        $response->assertStatus(401);

        // User access not allowed on admin API
        $response = $this->actingAs($user)->get("api/v4/skus");
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->get("api/v4/skus");
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
    }

    /**
     * Test fetching SKUs list for a user (GET /users/<id>/skus)
     */
    public function testUserSkus(): void
    {
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $user = $this->getTestUser('john@kolab.org');

        // Unauth access not allowed
        $response = $this->get("api/v4/users/{$user->id}/skus");
        $response->assertStatus(401);

        // Non-admin access not allowed
        $response = $this->actingAs($user)->get("api/v4/users/{$user->id}/skus");
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->get("api/v4/users/{$user->id}/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json['list']);
        // Note: Details are tested where we test API\V4\SkusController
    }
}
