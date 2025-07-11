<?php

namespace Tests\Feature\Controller\Reseller;

use App\Auth\SecondFactor;
use App\Domain;
use App\Plan;
use App\SharedFolderAlias;
use App\Sku;
use App\Tenant;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UsersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useResellerUrl();

        $this->deleteTestUser('UsersControllerTest1@userscontroller.com');
        $this->deleteTestUser('test@testsearch.com');
        $this->deleteTestDomain('testsearch.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('UsersControllerTest1@userscontroller.com');
        $this->deleteTestUser('test@testsearch.com');
        $this->deleteTestDomain('testsearch.com');

        SharedFolderAlias::truncate();

        parent::tearDown();
    }

    /**
     * Test user deleting (DELETE /api/v4/users/<id>)
     */
    public function testDestroy(): void
    {
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');

        // Test unauth access
        $response = $this->delete("api/v4/users/{$user->id}");
        $response->assertStatus(401);

        // The end-point does not exist
        $response = $this->actingAs($reseller1)->delete("api/v4/users/{$user->id}");
        $response->assertStatus(404);
    }

    /**
     * Test users searching (/api/v4/users)
     */
    public function testIndex(): void
    {
        Queue::fake();

        $user = $this->getTestUser('john@kolab.org');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');

        // Guess access
        $response = $this->get("api/v4/users");
        $response->assertStatus(401);

        // Normal user
        $response = $this->actingAs($user)->get("api/v4/users");
        $response->assertStatus(403);

        // Admin user
        $response = $this->actingAs($admin)->get("api/v4/users");
        $response->assertStatus(403);

        // Search with no search criteria
        $response = $this->actingAs($reseller2)->get("api/v4/users");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        // Search with no matches expected
        $response = $this->actingAs($reseller2)->get("api/v4/users?search=abcd1234efgh5678");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        // Search by domain in another tenant
        $response = $this->actingAs($reseller2)->get("api/v4/users?search=kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        // Search by user ID in another tenant
        $response = $this->actingAs($reseller2)->get("api/v4/users?search={$user->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        // Search by email (primary) - existing user in another tenant
        $response = $this->actingAs($reseller2)->get("api/v4/users?search=john@kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        // Search by owner - existing user in another tenant
        $response = $this->actingAs($reseller2)->get("api/v4/users?owner={$user->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');
        $folder->setAliases(['folder-alias@kolab.org']);

        // Search by shared folder email
        $response = $this->actingAs($reseller1)->get("api/v4/users?search={$folder->email}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by shared folder alias
        $response = $this->actingAs($reseller1)->get("api/v4/users?search=folder-alias@kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Create a domain with some users in the Sample Tenant so we have anything to search for
        $domain = $this->getTestDomain('testsearch.com', ['type' => Domain::TYPE_EXTERNAL]);
        $domain->tenant_id = $reseller2->tenant_id;
        $domain->save();
        $user = $this->getTestUser('test@testsearch.com');
        $user->tenant_id = $reseller2->tenant_id;
        $user->save();
        $plan = Plan::where('title', 'group')->first();
        $user->assignPlan($plan, $domain);
        $user->setAliases(['alias@testsearch.com']);
        $user->setSetting('external_email', 'john.doe.external@gmail.com');

        // Search by domain
        $response = $this->actingAs($reseller2)->get("api/v4/users?search=testsearch.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by user ID
        $response = $this->actingAs($reseller2)->get("api/v4/users?search={$user->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by email (primary) - existing user in reseller's tenant
        $response = $this->actingAs($reseller2)->get("api/v4/users?search=test@testsearch.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by email (alias)
        $response = $this->actingAs($reseller2)->get("api/v4/users?search=alias@testsearch.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by email (external), there are two users with this email, but only one
        // in the reseller's tenant
        $response = $this->actingAs($reseller2)->get("api/v4/users?search=john.doe.external@gmail.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by owner
        $response = $this->actingAs($reseller2)->get("api/v4/users?owner={$user->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Deleted users/domains
        $user->delete();

        $response = $this->actingAs($reseller2)->get("api/v4/users?search=test@testsearch.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);
        $this->assertTrue($json['list'][0]['isDeleted']);

        $response = $this->actingAs($reseller2)->get("api/v4/users?search=alias@testsearch.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);
        $this->assertTrue($json['list'][0]['isDeleted']);

        $response = $this->actingAs($reseller2)->get("api/v4/users?search=testsearch.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);
        $this->assertTrue($json['list'][0]['isDeleted']);
    }

    /**
     * Test reseting 2FA (POST /api/v4/users/<user-id>/reset-2fa)
     */
    public function testReset2FA(): void
    {
        Queue::fake(); // disable jobs

        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');

        $sku2fa = Sku::withEnvTenantContext()->where('title', '2fa')->first();
        $user->assignSku($sku2fa);
        SecondFactor::seed('userscontrollertest1@userscontroller.com');

        // Test unauthorized access
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/reset-2fa", []);
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/reset-2fa", []);
        $response->assertStatus(403);

        $response = $this->actingAs($reseller2)->post("/api/v4/users/{$user->id}/reset-2fa", []);
        $response->assertStatus(404);

        // Touching admins is forbidden
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$admin->id}/reset-2fa", []);
        $response->assertStatus(403);

        $entitlements = $user->fresh()->entitlements()->where('sku_id', $sku2fa->id)->get();
        $this->assertCount(1, $entitlements);

        $sf = new SecondFactor($user);
        $this->assertCount(1, $sf->factors());

        // Test reseting 2FA
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$user->id}/reset-2fa", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("2-Factor authentication reset successfully.", $json['message']);
        $this->assertCount(2, $json);

        $entitlements = $user->fresh()->entitlements()->where('sku_id', $sku2fa->id)->get();
        $this->assertCount(0, $entitlements);

        $sf = new SecondFactor($user);
        $this->assertCount(0, $sf->factors());
    }

    /**
     * Test reseting Geo-Lock (POST /api/v4/users/<user-id>/reset-geolock)
     */
    public function testResetGeoLock(): void
    {
        Queue::fake(); // disable jobs

        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');

        $user->setConfig(['limit_geo' => ['US']]);

        // Test unauthorized access
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/reset-geolock", []);
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/reset-geolock", []);
        $response->assertStatus(403);

        $response = $this->actingAs($reseller2)->post("/api/v4/users/{$user->id}/reset-geolock", []);
        $response->assertStatus(404);

        // Touching admins is forbidden
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$admin->id}/reset-geolock", []);
        $response->assertStatus(403);

        // Test reseting Geo-Lock
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$user->id}/reset-geolock", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("Geo-lockin setup reset successfully.", $json['message']);
        $this->assertCount(2, $json);
        $this->assertNull($user->getSetting('limit_geo'));
    }

    /**
     * Test resync (POST /api/v4/users/<user-id>/resync)
     */
    public function testResync(): void
    {
        Queue::fake();

        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');

        // Test unauthorized access to admin API
        // Test unauthorized access
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/resync", []);
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/resync", []);
        $response->assertStatus(403);

        $response = $this->actingAs($reseller2)->post("/api/v4/users/{$user->id}/resync", []);
        $response->assertStatus(404);

        // Touching admins is forbidden
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$admin->id}/resync", []);
        $response->assertStatus(403);

        // Test resync
        \Artisan::shouldReceive('call')->once()->with('user:resync', ['user' => $user->id]);

        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$user->id}/resync", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("User synchronization has been started.", $json['message']);
    }

    /**
     * Test adding beta SKU (POST /api/v4/users/<user-id>/skus/beta)
     */
    public function testAddBetaSku(): void
    {
        Queue::fake(); // disable jobs

        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');
        $sku = Sku::withEnvTenantContext()->where(['title' => 'beta'])->first();

        // Test unauthorized access to reseller API
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/skus/beta", []);
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/skus/beta", []);
        $response->assertStatus(403);

        $response = $this->actingAs($reseller2)->post("/api/v4/users/{$user->id}/skus/beta", []);
        $response->assertStatus(404);

        // Touching admins is forbidden
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$admin->id}/skus/beta", []);
        $response->assertStatus(403);

        // For now we allow only the beta sku
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$user->id}/skus/mailbox", []);
        $response->assertStatus(404);

        $entitlements = $user->entitlements()->where('sku_id', $sku->id)->get();
        $this->assertCount(0, $entitlements);

        // Test adding the beta sku
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$user->id}/skus/beta", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("The subscription added successfully.", $json['message']);
        $this->assertSame(0, $json['sku']['cost']);
        $this->assertSame($sku->id, $json['sku']['id']);
        $this->assertSame($sku->name, $json['sku']['name']);
        $this->assertCount(3, $json);

        $entitlements = $user->entitlements()->where('sku_id', $sku->id)->get();
        $this->assertCount(1, $entitlements);

        // Test adding the beta sku again, expect an error
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$user->id}/skus/beta", []);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("The subscription already exists.", $json['message']);
        $this->assertCount(2, $json);

        $entitlements = $user->entitlements()->where('sku_id', $sku->id)->get();
        $this->assertCount(1, $entitlements);
    }

    /**
     * Test user creation (POST /api/v4/users)
     */
    public function testStore(): void
    {
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));

        // The end-point does not exist
        $response = $this->actingAs($reseller1)->post("/api/v4/users", []);
        $response->assertStatus(404);
    }

    /**
     * Test user suspending (POST /api/v4/users/<user-id>/suspend)
     */
    public function testSuspend(): void
    {
        Queue::fake(); // disable jobs

        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');

        // Test unauthorized access
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/suspend", []);
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/suspend", []);
        $response->assertStatus(403);

        $response = $this->actingAs($reseller2)->post("/api/v4/users/{$user->id}/suspend", []);
        $response->assertStatus(404);

        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$admin->id}/suspend", []);
        $response->assertStatus(403);

        $this->assertFalse($user->isSuspended());

        // Test suspending the user
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$user->id}/suspend", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("User suspended successfully.", $json['message']);
        $this->assertCount(2, $json);

        $this->assertTrue($user->fresh()->isSuspended());
    }

    /**
     * Test user un-suspending (POST /api/v4/users/<user-id>/unsuspend)
     */
    public function testUnsuspend(): void
    {
        Queue::fake(); // disable jobs

        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');

        // Test unauthorized access to admin API
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/unsuspend", []);
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/unsuspend", []);
        $response->assertStatus(403);

        $response = $this->actingAs($reseller2)->post("/api/v4/users/{$user->id}/unsuspend", []);
        $response->assertStatus(404);

        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$admin->id}/unsuspend", []);
        $response->assertStatus(403);

        $this->assertFalse($user->isSuspended());
        $user->suspend();
        $this->assertTrue($user->isSuspended());

        // Test suspending the user
        $response = $this->actingAs($reseller1)->post("/api/v4/users/{$user->id}/unsuspend", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("User unsuspended successfully.", $json['message']);
        $this->assertCount(2, $json);

        $this->assertFalse($user->fresh()->isSuspended());
    }

    /**
     * Test user update (PUT /api/v4/users/<user-id>)
     */
    public function testUpdate(): void
    {
        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');

        // Test unauthorized access
        $response = $this->actingAs($user)->put("/api/v4/users/{$user->id}", []);
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->put("/api/v4/users/{$user->id}", []);
        $response->assertStatus(403);

        $response = $this->actingAs($reseller2)->put("/api/v4/users/{$user->id}", []);
        $response->assertStatus(404);

        $response = $this->actingAs($reseller1)->put("/api/v4/users/{$admin->id}", []);
        $response->assertStatus(403);

        // Test updatig the user data (empty data)
        $response = $this->actingAs($reseller1)->put("/api/v4/users/{$user->id}", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("User data updated successfully.", $json['message']);
        $this->assertCount(2, $json);

        // Test error handling
        $post = ['external_email' => 'aaa'];
        $response = $this->actingAs($reseller1)->put("/api/v4/users/{$user->id}", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("The external email must be a valid email address.", $json['errors']['external_email'][0]);
        $this->assertCount(2, $json);

        // Test real update
        $post = ['external_email' => 'modified@test.com'];
        $response = $this->actingAs($reseller1)->put("/api/v4/users/{$user->id}", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("User data updated successfully.", $json['message']);
        $this->assertCount(2, $json);
        $this->assertSame('modified@test.com', $user->getSetting('external_email'));
    }
}
