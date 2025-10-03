<?php

namespace Tests\Feature\Controller;

use App\Discount;
use App\Domain;
use App\Entitlement;
use App\Package;
use App\Sku;
use App\Tenant;
use App\User;
use App\Wallet;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DomainsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('test1@' . \config('app.domain'));
        $this->deleteTestUser('test2@' . \config('app.domain'));
        $this->deleteTestUser('test1@domainscontroller.com');
        $this->deleteTestDomain('domainscontroller.com');
        Sku::where('title', 'test')->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('test1@' . \config('app.domain'));
        $this->deleteTestUser('test2@' . \config('app.domain'));
        $this->deleteTestUser('test1@domainscontroller.com');
        $this->deleteTestDomain('domainscontroller.com');
        Sku::where('title', 'test')->delete();

        $domain = $this->getTestDomain('kolab.org');
        $domain->settings()->whereIn('key', ['spf_whitelist'])->delete();

        parent::tearDown();
    }

    /**
     * Test domain confirm request
     *
     * @group skipci
     */
    public function testConfirm(): void
    {
        Queue::fake();

        $sku_domain = Sku::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $user = $this->getTestUser('test1@domainscontroller.com');
        $domain = $this->getTestDomain('domainscontroller.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_EXTERNAL,
        ]);

        Entitlement::create([
            'wallet_id' => $user->wallets()->first()->id,
            'sku_id' => $sku_domain->id,
            'entitleable_id' => $domain->id,
            'entitleable_type' => Domain::class,
        ]);

        $response = $this->actingAs($user)->get("api/v4/domains/{$domain->id}/confirm");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('error', $json['status']);
        $this->assertSame('Domain ownership confirmation failed.', $json['message']);

        $domain->status |= Domain::STATUS_CONFIRMED;
        $domain->save();

        $response = $this->actingAs($user)->get("api/v4/domains/{$domain->id}/confirm");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame('Domain ownership confirmed successfully.', $json['message']);
        $this->assertTrue(is_array($json['statusInfo']));

        // Not authorized access
        $response = $this->actingAs($john)->get("api/v4/domains/{$domain->id}/confirm");
        $response->assertStatus(403);

        // Authorized access by additional account controller
        $domain = $this->getTestDomain('kolab.org');
        $response = $this->actingAs($ned)->get("api/v4/domains/{$domain->id}/confirm");
        $response->assertStatus(200);
    }

    /**
     * Test domain delete request (DELETE /api/v4/domains/<id>)
     */
    public function testDestroy(): void
    {
        Queue::fake();

        $sku_domain = Sku::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $john = $this->getTestUser('john@kolab.org');
        $johns_domain = $this->getTestDomain('kolab.org');
        $user1 = $this->getTestUser('test1@' . \config('app.domain'));
        $user2 = $this->getTestUser('test2@' . \config('app.domain'));
        $domain = $this->getTestDomain('domainscontroller.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_EXTERNAL,
        ]);

        Entitlement::create([
            'wallet_id' => $user1->wallets()->first()->id,
            'sku_id' => $sku_domain->id,
            'entitleable_id' => $domain->id,
            'entitleable_type' => Domain::class,
        ]);

        // Not authorized access
        $response = $this->actingAs($john)->delete("api/v4/domains/{$domain->id}");
        $response->assertStatus(403);

        // Can't delete non-empty domain
        $response = $this->actingAs($john)->delete("api/v4/domains/{$johns_domain->id}");
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('error', $json['status']);
        $this->assertSame('Unable to delete a domain with assigned users or other objects.', $json['message']);

        // Successful deletion
        $response = $this->actingAs($user1)->delete("api/v4/domains/{$domain->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Domain deleted successfully.', $json['message']);
        $this->assertTrue($domain->fresh()->trashed());

        // Authorized access by additional account controller
        $this->deleteTestDomain('domainscontroller.com');
        $domain = $this->getTestDomain('domainscontroller.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_EXTERNAL,
        ]);

        Entitlement::create([
            'wallet_id' => $user1->wallets()->first()->id,
            'sku_id' => $sku_domain->id,
            'entitleable_id' => $domain->id,
            'entitleable_type' => Domain::class,
        ]);

        $user1->wallets()->first()->addController($user2);

        $response = $this->actingAs($user2)->delete("api/v4/domains/{$domain->id}");
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Domain deleted successfully.', $json['message']);
        $this->assertTrue($domain->fresh()->trashed());
    }

    /**
     * Test fetching domains list
     */
    public function testIndex(): void
    {
        // User with no domains
        $user = $this->getTestUser('test1@domainscontroller.com');
        $response = $this->actingAs($user)->get("api/v4/domains");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame(0, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame("0 domains have been found.", $json['message']);
        $this->assertSame([], $json['list']);

        // User with custom domain(s)
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        $response = $this->actingAs($john)->get("api/v4/domains");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame("1 domains have been found.", $json['message']);
        $this->assertCount(1, $json['list']);
        $this->assertSame('kolab.org', $json['list'][0]['namespace']);
        // Values below are tested by Unit tests
        $this->assertArrayHasKey('isConfirmed', $json['list'][0]);
        $this->assertArrayHasKey('isDeleted', $json['list'][0]);
        $this->assertArrayHasKey('isVerified', $json['list'][0]);
        $this->assertArrayHasKey('isSuspended', $json['list'][0]);
        $this->assertArrayHasKey('isActive', $json['list'][0]);
        if (\config('app.with_ldap')) {
            $this->assertArrayHasKey('isLdapReady', $json['list'][0]);
        } else {
            $this->assertArrayNotHasKey('isLdapReady', $json['list'][0]);
        }
        $this->assertArrayHasKey('isReady', $json['list'][0]);

        $response = $this->actingAs($ned)->get("api/v4/domains");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(1, $json['list']);
        $this->assertSame('kolab.org', $json['list'][0]['namespace']);
    }

    /**
     * Test domain config update (POST /api/v4/domains/<domain>/config)
     */
    public function testSetConfig(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $domain = $this->getTestDomain('kolab.org');
        $domain->setSetting('spf_whitelist', null);

        // Test unknown domain id
        $post = ['spf_whitelist' => []];
        $response = $this->actingAs($john)->post("/api/v4/domains/123/config", $post);
        $json = $response->json();

        $response->assertStatus(404);

        // Test access by user not being a wallet controller
        $post = ['spf_whitelist' => []];
        $response = $this->actingAs($jack)->post("/api/v4/domains/{$domain->id}/config", $post);
        $json = $response->json();

        $response->assertStatus(403);

        $this->assertSame('error', $json['status']);
        $this->assertSame("Access denied", $json['message']);
        $this->assertCount(2, $json);

        // Test some invalid data
        $post = ['grey' => 1];
        $response = $this->actingAs($john)->post("/api/v4/domains/{$domain->id}/config", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertCount(1, $json['errors']);
        $this->assertSame('The requested configuration parameter is not supported.', $json['errors']['grey']);

        $this->assertNull($domain->fresh()->getSetting('spf_whitelist'));

        // Test some valid data
        $post = ['spf_whitelist' => ['.test.domain.com']];
        $response = $this->actingAs($john)->post("/api/v4/domains/{$domain->id}/config", $post);

        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Domain settings updated successfully.', $json['message']);

        $expected = \json_encode($post['spf_whitelist']);
        $this->assertSame($expected, $domain->fresh()->getSetting('spf_whitelist'));

        // Test input validation
        $post = ['spf_whitelist' => ['aaa']];
        $response = $this->actingAs($john)->post("/api/v4/domains/{$domain->id}/config", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame(
            'The entry format is invalid. Expected a domain name starting with a dot.',
            $json['errors']['spf_whitelist'][0]
        );

        $this->assertSame($expected, $domain->fresh()->getSetting('spf_whitelist'));
    }

    /**
     * Test fetching domain info
     */
    public function testShow(): void
    {
        $sku_domain = Sku::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $user = $this->getTestUser('test1@domainscontroller.com');
        $domain = $this->getTestDomain('domainscontroller.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_EXTERNAL,
        ]);

        $discount = Discount::withEnvTenantContext()->where('code', 'TEST')->first();
        $wallet = $user->wallet();
        $wallet->discount()->associate($discount);
        $wallet->save();

        Entitlement::create([
            'wallet_id' => $user->wallets()->first()->id,
            'sku_id' => $sku_domain->id,
            'entitleable_id' => $domain->id,
            'entitleable_type' => Domain::class,
        ]);

        $response = $this->actingAs($user)->get("api/v4/domains/{$domain->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($domain->id, $json['id']);
        $this->assertSame($domain->namespace, $json['namespace']);
        $this->assertSame($domain->status, $json['status']);
        $this->assertSame($domain->type, $json['type']);
        $this->assertSame($domain->hash(Domain::HASH_TEXT), $json['hash_text']);
        $this->assertSame($domain->hash(Domain::HASH_CNAME), $json['hash_cname']);
        $this->assertSame($domain->hash(Domain::HASH_CODE), $json['hash_code']);
        $this->assertSame([], $json['config']['spf_whitelist']);
        $this->assertCount(4, $json['mx']);
        $this->assertTrue(str_contains(implode("\n", $json['mx']), $domain->namespace));
        $this->assertCount(1, $json['dns']);
        $this->assertTrue(str_contains(implode("\n", $json['dns']), $domain->namespace));
        $this->assertTrue(str_contains(implode("\n", $json['dns']), $domain->hash()));
        $this->assertTrue(is_array($json['statusInfo']));
        // Values below are tested by Unit tests
        $this->assertArrayHasKey('isConfirmed', $json);
        $this->assertArrayHasKey('isDeleted', $json);
        $this->assertArrayHasKey('isVerified', $json);
        $this->assertArrayHasKey('isSuspended', $json);
        $this->assertArrayHasKey('isActive', $json);
        if (\config('app.with_ldap')) {
            $this->assertArrayHasKey('isLdapReady', $json);
        } else {
            $this->assertArrayNotHasKey('isLdapReady', $json);
        }
        $this->assertArrayHasKey('isReady', $json);
        $this->assertCount(1, $json['skus']);
        $this->assertSame(1, $json['skus'][$sku_domain->id]['count']);
        $this->assertSame([0], $json['skus'][$sku_domain->id]['costs']);
        $this->assertSame($wallet->id, $json['wallet']['id']);
        $this->assertSame($wallet->balance, $json['wallet']['balance']);
        $this->assertSame($wallet->currency, $json['wallet']['currency']);
        $this->assertSame($discount->discount, $json['wallet']['discount']);
        $this->assertSame($discount->description, $json['wallet']['discount_description']);

        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');

        // Not authorized - Other account domain
        $response = $this->actingAs($john)->get("api/v4/domains/{$domain->id}");
        $response->assertStatus(403);

        $domain = $this->getTestDomain('kolab.org');

        // Ned is an additional controller on kolab.org's wallet
        $response = $this->actingAs($ned)->get("api/v4/domains/{$domain->id}");
        $response->assertStatus(200);

        // Jack has no entitlement/control over kolab.org
        $response = $this->actingAs($jack)->get("api/v4/domains/{$domain->id}");
        $response->assertStatus(403);
    }

    /**
     * Test fetching SKUs list for a domain (GET /domains/<id>/skus)
     */
    public function testSkus(): void
    {
        $user = $this->getTestUser('john@kolab.org');
        $domain = $this->getTestDomain('kolab.org');

        // Unauth access not allowed
        $response = $this->get("api/v4/domains/{$domain->id}/skus");
        $response->assertStatus(401);

        // Create an sku for another tenant, to make sure it is not included in the result
        $nsku = Sku::create([
            'title' => 'test',
            'name' => 'Test',
            'description' => '',
            'active' => true,
            'cost' => 100,
            'handler_class' => 'App\Handlers\Domain',
        ]);
        $tenant = Tenant::whereNotIn('id', [\config('app.tenant_id')])->first();
        $nsku->tenant_id = $tenant->id;
        $nsku->save();

        $response = $this->actingAs($user)->get("api/v4/domains/{$domain->id}/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(1, $json['list']);
        $this->assertSkuElement('domain-hosting', $json['list'][0], [
            'prio' => 0,
            'type' => 'domain',
            'handler' => 'DomainHosting',
            'enabled' => true,
            'readonly' => true,
        ]);
    }

    /**
     * Test fetching domain status (GET /api/v4/domains/<domain-id>/status)
     * and forcing setup process update (?refresh=1)
     *
     * @group dns
     */
    public function testStatus(): void
    {
        $withLdap = \config('app.with_ldap');

        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $domain = $this->getTestDomain('kolab.org');

        // Test unauthorized access
        $response = $this->actingAs($jack)->get("/api/v4/domains/{$domain->id}/status");
        $response->assertStatus(403);

        $domain->status = Domain::STATUS_NEW | Domain::STATUS_ACTIVE;
        if ($withLdap) {
            $domain->status |= Domain::STATUS_LDAP_READY;
        }
        $domain->save();

        // Get domain status
        $response = $this->actingAs($john)->get("/api/v4/domains/{$domain->id}/status");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertFalse($json['isVerified']);
        $this->assertFalse($json['isReady']);
        $this->assertFalse($json['isDone']);
        $this->assertCount($withLdap ? 4 : 3, $json['process']);
        $this->assertSame('domain-verified', $json['process'][$withLdap ? 2 : 1]['label']);
        $this->assertFalse($json['process'][$withLdap ? 2 : 1]['state']);
        $this->assertTrue(empty($json['status']));
        $this->assertTrue(empty($json['message']));

        // Now "reboot" the process and verify the domain
        $response = $this->actingAs($john)->get("/api/v4/domains/{$domain->id}/status?refresh=1");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertTrue($json['isVerified']);
        $this->assertTrue($json['isReady']);
        $this->assertTrue($json['isDone']);
        $this->assertCount($withLdap ? 4 : 3, $json['process']);
        $this->assertSame('domain-verified', $json['process'][$withLdap ? 2 : 1]['label']);
        $this->assertTrue($json['process'][$withLdap ? 2 : 1]['state']);
        $this->assertSame('domain-confirmed', $json['process'][$withLdap ? 3 : 2]['label']);
        $this->assertTrue($json['process'][$withLdap ? 3 : 2]['state']);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Setup process finished successfully.', $json['message']);

        // TODO: Test completing all process steps
    }

    /**
     * Test domain creation (POST /api/v4/domains)
     */
    public function testStore(): void
    {
        Queue::fake();

        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');

        // Test empty request
        $response = $this->actingAs($john)->post("/api/v4/domains", []);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("The namespace field is required.", $json['errors']['namespace'][0]);
        $this->assertCount(1, $json['errors']);
        $this->assertCount(1, $json['errors']['namespace']);
        $this->assertCount(2, $json);

        // Test access by user not being a wallet controller
        $post = ['namespace' => 'domainscontroller.com'];
        $response = $this->actingAs($jack)->post("/api/v4/domains", $post);
        $json = $response->json();

        $response->assertStatus(403);

        $this->assertSame('error', $json['status']);
        $this->assertSame("Access denied", $json['message']);
        $this->assertCount(2, $json);

        // Test some invalid data
        $post = ['namespace' => '--'];
        $response = $this->actingAs($john)->post("/api/v4/domains", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertSame('The specified domain is invalid.', $json['errors']['namespace'][0]);
        $this->assertCount(1, $json['errors']);
        $this->assertCount(1, $json['errors']['namespace']);

        // Test an existing domain
        $post = ['namespace' => 'kolab.org'];
        $response = $this->actingAs($john)->post("/api/v4/domains", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertSame('The specified domain is not available.', $json['errors']['namespace']);

        $package_kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $package_domain = Package::withEnvTenantContext()->where('title', 'domain-hosting')->first();

        // Missing package
        $post = ['namespace' => 'domainscontroller.com'];
        $response = $this->actingAs($john)->post("/api/v4/domains", $post);
        $json = $response->json();

        $response->assertStatus(422);

        $this->assertSame('error', $json['status']);
        $this->assertSame("Package is required.", $json['errors']['package']);
        $this->assertCount(2, $json);

        // Invalid package
        $post['package'] = $package_kolab->id;
        $response = $this->actingAs($john)->post("/api/v4/domains", $post);
        $json = $response->json();

        $response->assertStatus(422);

        $this->assertSame('error', $json['status']);
        $this->assertSame("Invalid package selected.", $json['errors']['package']);
        $this->assertCount(2, $json);

        // Test full and valid data
        $post['package'] = $package_domain->id;
        $response = $this->actingAs($john)->post("/api/v4/domains", $post);
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame('success', $json['status']);
        $this->assertSame("Domain created successfully.", $json['message']);
        $this->assertCount(2, $json);

        $domain = Domain::where('namespace', $post['namespace'])->first();
        $this->assertInstanceOf(Domain::class, $domain);

        // Assert the new domain entitlements
        $this->assertEntitlements($domain, ['domain-hosting']);

        // Assert the wallet to which the new domain should be assigned to
        $wallet = $domain->wallet();
        $this->assertSame($john->wallets->first()->id, $wallet->id);

        // Test re-creating a domain
        $domain->delete();

        $response = $this->actingAs($john)->post("/api/v4/domains", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("Domain created successfully.", $json['message']);
        $this->assertCount(2, $json);

        $domain = Domain::where('namespace', $post['namespace'])->first();
        $this->assertInstanceOf(Domain::class, $domain);
        $this->assertEntitlements($domain, ['domain-hosting']);
        $wallet = $domain->wallet();
        $this->assertSame($john->wallets->first()->id, $wallet->id);

        // Test creating a domain that is soft-deleted and belongs to another user
        $domain->delete();
        $domain->entitlements()->withTrashed()->update(['wallet_id' => $jack->wallets->first()->id]);

        $response = $this->actingAs($john)->post("/api/v4/domains", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertSame('The specified domain is not available.', $json['errors']['namespace']);

        // Test acting as account controller (not owner)
        $ned = $this->getTestUser('ned@kolab.org');
        $domain->forceDelete();

        $response = $this->actingAs($ned)->post("/api/v4/domains", $post);
        $response->assertStatus(200);

        $domain = Domain::where('namespace', $post['namespace'])->first();
        $this->assertSame($john->wallets->first()->id, $domain->wallet()->id);
    }
}
