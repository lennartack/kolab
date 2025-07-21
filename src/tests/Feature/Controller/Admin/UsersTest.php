<?php

namespace Tests\Feature\Controller\Admin;

use App\Auth\SecondFactor;
use App\Domain;
use App\EventLog;
use App\Payment;
use App\Plan;
use App\SharedFolderAlias;
use App\Sku;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UsersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useAdminUrl();

        $this->deleteTestUser('UsersControllerTest1@userscontroller.com');
        $this->deleteTestUser('test@testsearch.com');
        $this->deleteTestDomain('testsearch.com');
        $this->deleteTestGroup('group-test@kolab.org');

        $jack = $this->getTestUser('jack@kolab.org');
        $jack->setSetting('external_email', null);

        SharedFolderAlias::truncate();
        Payment::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('UsersControllerTest1@userscontroller.com');
        $this->deleteTestUser('test@testsearch.com');
        $this->deleteTestDomain('testsearch.com');

        $jack = $this->getTestUser('jack@kolab.org');
        $jack->setSetting('external_email', null);

        SharedFolderAlias::truncate();
        Payment::query()->delete();

        parent::tearDown();
    }

    /**
     * Test user deleting (DELETE /api/v4/users/<id>)
     */
    public function testDestroy(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');

        // Test unauth access
        $response = $this->delete("api/v4/users/{$user->id}");
        $response->assertStatus(401);

        // The end-point does not exist
        $response = $this->actingAs($admin)->delete("api/v4/users/{$user->id}");
        $response->assertStatus(404);
    }

    /**
     * Test inspect request (GET /api/v4/inspect-request)
     */
    public function testInspectRequest(): void
    {
        // No authentication required here
        $this->get("api/v4/inspect-request")
            ->assertStatus(200)
            ->assertJson(function ($json) {
                $json->hasAll(['ip', 'clientIps', 'isFromTrustedProxy', 'headers']);
            });

        // Normal users have no access to this end-point
        self::useRegularUrl();
        $this->get("api/v4/inspect-request")->assertStatus(404);
    }

    /**
     * Test users searching (/api/v4/users)
     */
    public function testIndex(): void
    {
        $user = $this->getTestUser('john@kolab.org');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($user->wallets->first());

        // Non-admin user
        $response = $this->actingAs($user)->get("api/v4/users");
        $response->assertStatus(403);

        // Search with no search criteria
        $response = $this->actingAs($admin)->get("api/v4/users");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        // Search with no matches expected
        $response = $this->actingAs($admin)->get("api/v4/users?search=abcd1234efgh5678");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        // Search by domain
        $response = $this->actingAs($admin)->get("api/v4/users?search=kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by user ID
        $response = $this->actingAs($admin)->get("api/v4/users?search={$user->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by email (primary)
        $response = $this->actingAs($admin)->get("api/v4/users?search=john@kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by email (alias)
        $response = $this->actingAs($admin)->get("api/v4/users?search=john.doe@kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by email (external), expect two users in a result
        $jack = $this->getTestUser('jack@kolab.org');
        $jack->setSetting('external_email', 'john.doe.external@gmail.com');

        $response = $this->actingAs($admin)->get("api/v4/users?search=john.doe.external@gmail.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(2, $json['count']);
        $this->assertCount(2, $json['list']);

        $emails = array_column($json['list'], 'email');

        $this->assertContains($user->email, $emails);
        $this->assertContains($jack->email, $emails);

        // Search by owner
        $response = $this->actingAs($admin)->get("api/v4/users?owner={$user->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(4, $json['count']);
        $this->assertCount(4, $json['list']);

        // Search by owner (Ned is a controller on John's wallets,
        // here we expect only users assigned to Ned's wallet(s))
        $ned = $this->getTestUser('ned@kolab.org');
        $response = $this->actingAs($admin)->get("api/v4/users?owner={$ned->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertCount(0, $json['list']);

        // Search by distribution list email
        $response = $this->actingAs($admin)->get("api/v4/users?search=group-test@kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by resource email
        $response = $this->actingAs($admin)->get("api/v4/users?search=resource-test1@kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');
        $folder->setAliases(['folder-alias@kolab.org']);

        // Search by shared folder email
        $response = $this->actingAs($admin)->get("api/v4/users?search={$folder->email}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Search by shared folder alias
        $response = $this->actingAs($admin)->get("api/v4/users?search=folder-alias@kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);

        // Deleted users/domains
        $domain = $this->getTestDomain('testsearch.com', ['type' => Domain::TYPE_EXTERNAL]);
        $user = $this->getTestUser('test@testsearch.com');
        $plan = Plan::where('title', 'group')->first();
        $user->assignPlan($plan, $domain);
        $user->setAliases(['alias@testsearch.com']);

        $wallet = $user->wallets()->first();
        $wallet->setSetting('mollie_id', 'cst_nonsense');

        Payment::create(
            [
                'id' => 'tr_nonsense',
                'wallet_id' => $wallet->id,
                'status' => 'paid',
                'amount' => 1337,
                'credit_amount' => 1337,
                'description' => 'nonsense transaction for testing',
                'provider' => 'self',
                'type' => 'oneoff',
                'currency' => 'CHF',
                'currency_amount' => 1337,
            ]
        );

        Queue::fake();
        $user->delete();

        $response = $this->actingAs($admin)->get("api/v4/users?search=test@testsearch.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);
        $this->assertTrue($json['list'][0]['isDeleted']);

        $response = $this->actingAs($admin)->get("api/v4/users?search=alias@testsearch.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);
        $this->assertTrue($json['list'][0]['isDeleted']);

        $response = $this->actingAs($admin)->get("api/v4/users?search=testsearch.com");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);
        $this->assertTrue($json['list'][0]['isDeleted']);

        $response = $this->actingAs($admin)->get("api/v4/users?search={$wallet->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);
        $this->assertTrue($json['list'][0]['isDeleted']);

        $response = $this->actingAs($admin)->get("api/v4/users?search=tr_nonsense");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(1, $json['count']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($user->id, $json['list'][0]['id']);
        $this->assertSame($user->email, $json['list'][0]['email']);
        $this->assertTrue($json['list'][0]['isDeleted']);

        $response = $this->actingAs($admin)->get("api/v4/users?search=cst_nonsense");
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
        Queue::fake();

        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');

        $sku2fa = Sku::withEnvTenantContext()->where(['title' => '2fa'])->first();
        $user->assignSku($sku2fa);
        SecondFactor::seed('userscontrollertest1@userscontroller.com');

        // Test unauthorized access to admin API
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/reset-2fa", []);
        $response->assertStatus(403);

        $entitlements = $user->fresh()->entitlements()->where('sku_id', $sku2fa->id)->get();
        $this->assertCount(1, $entitlements);

        $sf = new SecondFactor($user);
        $this->assertCount(1, $sf->factors());

        // Test reseting 2FA
        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/reset-2fa", []);
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
        Queue::fake();

        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $user->setConfig(['limit_geo' => ['US']]);

        // Test unauthorized access to admin API
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/reset-geolock", []);
        $response->assertStatus(403);

        // Test reseting Geo-Lock
        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/reset-geolock", []);
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

        // Test unauthorized access to admin API
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/resync", []);
        $response->assertStatus(403);

        // Test resync
        \Artisan::shouldReceive('call')->once()->with('user:resync', ['user' => $user->id]);

        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/resync", []);
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
        Queue::fake();

        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $sku = Sku::withEnvTenantContext()->where(['title' => 'beta'])->first();

        // Test unauthorized access to admin API
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/skus/beta", []);
        $response->assertStatus(403);

        // For now we allow only the beta sku
        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/skus/mailbox", []);
        $response->assertStatus(404);

        $entitlements = $user->entitlements()->where('sku_id', $sku->id)->get();
        $this->assertCount(0, $entitlements);

        // Test adding the beta sku
        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/skus/beta", []);
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
        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/skus/beta", []);
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
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');

        // The end-point does not exist
        $response = $this->actingAs($admin)->post("/api/v4/users", []);
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

        // Test unauthorized access to admin API
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/suspend", []);
        $response->assertStatus(403);

        $this->assertFalse($user->isSuspended());

        // Test suspending the user
        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/suspend", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("User suspended successfully.", $json['message']);
        $this->assertCount(2, $json);
        $this->assertTrue($user->fresh()->isSuspended());

        $user->unsuspend();
        EventLog::truncate();

        // Test suspending the user with a comment
        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/suspend", ['comment' => 'Test']);
        $response->assertStatus(200);

        $where = [
            'object_id' => $user->id,
            'object_type' => User::class,
            'type' => EventLog::TYPE_SUSPENDED,
            'comment' => 'Test',
        ];

        $this->assertSame(1, EventLog::where($where)->count());
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

        // Test unauthorized access to admin API
        $response = $this->actingAs($user)->post("/api/v4/users/{$user->id}/unsuspend", []);
        $response->assertStatus(403);

        $this->assertFalse($user->isSuspended());
        $user->suspend();
        $this->assertTrue($user->isSuspended());

        // Test suspending the user
        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/unsuspend", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("User unsuspended successfully.", $json['message']);
        $this->assertCount(2, $json);
        $this->assertFalse($user->fresh()->isSuspended());

        $user->suspend();
        EventLog::truncate();

        // Test suspending the user with a comment
        $response = $this->actingAs($admin)->post("/api/v4/users/{$user->id}/unsuspend", ['comment' => 'Test']);
        $response->assertStatus(200);

        $where = [
            'object_id' => $user->id,
            'object_type' => User::class,
            'type' => EventLog::TYPE_UNSUSPENDED,
            'comment' => 'Test',
        ];

        $this->assertSame(1, EventLog::where($where)->count());
        $this->assertFalse($user->fresh()->isSuspended());
    }

    /**
     * Test user update (PUT /api/v4/users/<user-id>)
     */
    public function testUpdate(): void
    {
        $user = $this->getTestUser('UsersControllerTest1@userscontroller.com');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');

        // Test unauthorized access to admin API
        $response = $this->actingAs($user)->put("/api/v4/users/{$user->id}", []);
        $response->assertStatus(403);

        // Test updatig the user data (empty data)
        $response = $this->actingAs($admin)->put("/api/v4/users/{$user->id}", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("User data updated successfully.", $json['message']);
        $this->assertCount(2, $json);

        // Test error handling
        $post = ['external_email' => 'aaa'];
        $response = $this->actingAs($admin)->put("/api/v4/users/{$user->id}", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("The external email must be a valid email address.", $json['errors']['external_email'][0]);
        $this->assertCount(2, $json);

        // Test real update
        $post = ['external_email' => 'modified@test.com', 'debug' => 'roundcube'];
        $response = $this->actingAs($admin)->put("/api/v4/users/{$user->id}", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("User data updated successfully.", $json['message']);
        $this->assertCount(2, $json);
        $this->assertSame('modified@test.com', $user->getSetting('external_email'));
        $this->assertSame('roundcube', $user->getSetting('debug'));

        // Test unsetting debug
        $post = ['debug' => null];
        $response = $this->actingAs($admin)->put("/api/v4/users/{$user->id}", $post);
        $response->assertStatus(200);

        $this->assertNull($user->getSetting('debug'));
    }
}
