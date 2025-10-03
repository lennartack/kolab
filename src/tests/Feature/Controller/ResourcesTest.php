<?php

namespace Tests\Feature\Controller;

use App\Domain;
use App\Enums\ProcessState;
use App\Http\Controllers\API\V4\ResourcesController;
use App\Jobs\Resource\CreateJob;
use App\Resource;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResourcesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestResource('resource-test@kolab.org');
        Resource::where('name', 'Test Resource')->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestResource('resource-test@kolab.org');
        Resource::where('name', 'Test Resource')->delete();

        parent::tearDown();
    }

    /**
     * Test resource deleting (DELETE /api/v4/resources/<id>)
     */
    public function testDestroy(): void
    {
        // First create some groups to delete
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $resource = $this->getTestResource('resource-test@kolab.org');
        $resource->assignToWallet($john->wallets->first());

        // Test unauth access
        $response = $this->delete("api/v4/resources/{$resource->id}");
        $response->assertStatus(401);

        // Test non-existing resource
        $response = $this->actingAs($john)->delete("api/v4/resources/abc");
        $response->assertStatus(404);

        // Test access to other user's resource
        $response = $this->actingAs($jack)->delete("api/v4/resources/{$resource->id}");
        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("Access denied", $json['message']);
        $this->assertCount(2, $json);

        // Test removing a resource
        $response = $this->actingAs($john)->delete("api/v4/resources/{$resource->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("Resource deleted successfully.", $json['message']);
    }

    /**
     * Test resources listing (GET /api/v4/resources)
     */
    public function testIndex(): void
    {
        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        // Test unauth access
        $response = $this->get("api/v4/resources");
        $response->assertStatus(401);

        // Test a user with no resources
        $response = $this->actingAs($jack)->get("/api/v4/resources");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(5, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame(0, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame("0 resources have been found.", $json['message']);
        $this->assertSame([], $json['list']);

        // Test a user with two resources
        $response = $this->actingAs($john)->get("/api/v4/resources");
        $response->assertStatus(200);

        $json = $response->json();

        $resource = Resource::where('name', 'Conference Room #1')->first();

        $this->assertSame(2, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame("2 resources have been found.", $json['message']);
        $this->assertCount(2, $json['list']);
        $this->assertSame($resource->id, $json['list'][0]['id']);
        $this->assertSame($resource->email, $json['list'][0]['email']);
        $this->assertSame($resource->name, $json['list'][0]['name']);
        $this->assertArrayHasKey('isDeleted', $json['list'][0]);
        $this->assertArrayHasKey('isActive', $json['list'][0]);
        $this->assertArrayHasKey('isImapReady', $json['list'][0]);
        if (\config('app.with_ldap')) {
            $this->assertArrayHasKey('isLdapReady', $json['list'][0]);
        }

        // Test that another wallet controller has access to resources
        $response = $this->actingAs($ned)->get("/api/v4/resources");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(2, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame("2 resources have been found.", $json['message']);
        $this->assertCount(2, $json['list']);
        $this->assertSame($resource->email, $json['list'][0]['email']);
    }

    /**
     * Test resource config update (POST /api/v4/resources/<resource>/config)
     */
    public function testSetConfig(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $resource = $this->getTestResource('resource-test@kolab.org');
        $resource->assignToWallet($john->wallets->first());

        // Test unknown resource id
        $post = ['invitation_policy' => 'reject'];
        $response = $this->actingAs($john)->post("/api/v4/resources/123/config", $post);
        $json = $response->json();

        $response->assertStatus(404);

        // Test access by user not being a wallet controller
        $post = ['invitation_policy' => 'reject'];
        $response = $this->actingAs($jack)->post("/api/v4/resources/{$resource->id}/config", $post);
        $json = $response->json();

        $response->assertStatus(403);

        $this->assertSame('error', $json['status']);
        $this->assertSame("Access denied", $json['message']);
        $this->assertCount(2, $json);

        // Test some invalid data
        $post = ['test' => 1];
        $response = $this->actingAs($john)->post("/api/v4/resources/{$resource->id}/config", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertCount(1, $json['errors']);
        $this->assertSame('The requested configuration parameter is not supported.', $json['errors']['test']);

        $resource->refresh();

        $this->assertNull($resource->getSetting('test'));
        $this->assertNull($resource->getSetting('invitation_policy'));

        // Test some valid data
        $post = ['invitation_policy' => 'reject'];
        $response = $this->actingAs($john)->post("/api/v4/resources/{$resource->id}/config", $post);

        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Resource settings updated successfully.", $json['message']);

        $this->assertSame(['invitation_policy' => 'reject'], $resource->fresh()->getConfig());

        // Test input validation
        $post = ['invitation_policy' => 'aaa'];
        $response = $this->actingAs($john)->post("/api/v4/resources/{$resource->id}/config", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame(
            "The specified invitation policy is invalid.",
            $json['errors']['invitation_policy']
        );

        $this->assertSame(['invitation_policy' => 'reject'], $resource->fresh()->getConfig());
    }

    /**
     * Test fetching resource data/profile (GET /api/v4/resources/<resource>)
     */
    public function testShow(): void
    {
        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        $resource = $this->getTestResource('resource-test@kolab.org');
        $resource->assignToWallet($john->wallets->first());
        $resource->setSetting('invitation_policy', 'reject');

        // Test unauthorized access to a profile of other user
        $response = $this->get("/api/v4/resources/{$resource->id}");
        $response->assertStatus(401);

        // Test unauthorized access to a resource of another user
        $response = $this->actingAs($jack)->get("/api/v4/resources/{$resource->id}");
        $response->assertStatus(403);

        // John: Account owner - non-existing resource
        $response = $this->actingAs($john)->get("/api/v4/resources/abc");
        $response->assertStatus(404);

        // John: Account owner
        $response = $this->actingAs($john)->get("/api/v4/resources/{$resource->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($resource->id, $json['id']);
        $this->assertSame($resource->email, $json['email']);
        $this->assertSame($resource->name, $json['name']);
        $this->assertTrue(!empty($json['statusInfo']));
        $this->assertArrayHasKey('isDeleted', $json);
        $this->assertArrayHasKey('isActive', $json);
        $this->assertArrayHasKey('isImapReady', $json);
        if (\config('app.with_ldap')) {
            $this->assertArrayHasKey('isLdapReady', $json);
        }
        $this->assertSame(['invitation_policy' => 'reject'], $json['config']);
        $this->assertCount(1, $json['skus']);
    }

    /**
     * Test fetching SKUs list for a resource (GET /resources/<id>/skus)
     */
    public function testSkus(): void
    {
        Queue::fake();

        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');

        $resource = $this->getTestResource('resource-test@kolab.org');
        $resource->assignToWallet($john->wallets->first());

        // Unauth access not allowed
        $response = $this->get("api/v4/resources/{$resource->id}/skus");
        $response->assertStatus(401);

        // Unauthorized access not allowed
        $response = $this->actingAs($jack)->get("api/v4/resources/{$resource->id}/skus");
        $response->assertStatus(403);

        $response = $this->actingAs($john)->get("api/v4/resources/{$resource->id}/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(1, $json['list']);
        $this->assertSkuElement('resource', $json['list'][0], [
            'prio' => 0,
            'type' => 'resource',
            'handler' => 'Resource',
            'enabled' => true,
            'readonly' => true,
        ]);
    }

    /**
     * Test fetching a resource status (GET /api/v4/resources/<resource>/status)
     * and forcing setup process update (?refresh=1)
     */
    public function testStatus(): void
    {
        Queue::fake();

        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');

        $resource = $this->getTestResource('resource-test@kolab.org');
        $resource->assignToWallet($john->wallets->first());

        // Test unauthorized access
        $response = $this->get("/api/v4/resources/abc/status");
        $response->assertStatus(401);

        // Test unauthorized access
        $response = $this->actingAs($jack)->get("/api/v4/resources/{$resource->id}/status");
        $response->assertStatus(403);

        $resource->status = Resource::STATUS_NEW | Resource::STATUS_ACTIVE;
        $resource->save();

        // Get resource status
        $response = $this->actingAs($john)->get("/api/v4/resources/{$resource->id}/status");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertFalse($json['isReady']);
        $this->assertFalse($json['isDeleted']);
        $this->assertTrue($json['isActive']);
        $this->assertFalse($json['isImapReady']);
        $this->assertSame('resource-new', $json['process'][0]['label']);
        $this->assertTrue($json['process'][0]['state']);
        if (\config('app.with_ldap')) {
            $this->assertFalse($json['isLdapReady']);
            $this->assertSame('resource-ldap-ready', $json['process'][1]['label']);
            $this->assertFalse($json['process'][1]['state']);
            $this->assertSame('resource-imap-ready', $json['process'][2]['label']);
            $this->assertFalse($json['process'][2]['state']);
        } else {
            $this->assertSame('resource-imap-ready', $json['process'][1]['label']);
            $this->assertFalse($json['process'][1]['state']);
        }
        $this->assertTrue(empty($json['status']));
        $this->assertTrue(empty($json['message']));
        $this->assertSame(ProcessState::Running->value, $json['processState']);

        // Make sure the domain is confirmed (other test might unset that status)
        $domain = $this->getTestDomain('kolab.org');
        $domain->status |= Domain::STATUS_CONFIRMED;
        $domain->save();
        $resource->status |= Resource::STATUS_IMAP_READY;
        $resource->save();

        // Now "reboot" the process
        Queue::fake();
        $response = $this->actingAs($john)->get("/api/v4/resources/{$resource->id}/status?refresh=1");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertTrue($json['isImapReady']);
        $this->assertSame('success', $json['status']);
        if (\config('app.with_ldap')) {
            $this->assertFalse($json['isReady']);
            $this->assertFalse($json['isLdapReady']);
            $this->assertSame('resource-ldap-ready', $json['process'][1]['label']);
            $this->assertFalse($json['process'][1]['state']);
            $this->assertSame('resource-imap-ready', $json['process'][2]['label']);
            $this->assertTrue($json['process'][2]['state']);
            $this->assertSame('Setup process has been pushed. Please wait.', $json['message']);
            $this->assertSame(ProcessState::Waiting->value, $json['processState']);

            Queue::assertPushed(CreateJob::class, 1);
        } else {
            $this->assertTrue($json['isReady']);
            $this->assertSame('resource-imap-ready', $json['process'][1]['label']);
            $this->assertTrue($json['process'][1]['state']);
            $this->assertSame('Setup process finished successfully.', $json['message']);
            $this->assertSame(ProcessState::Done->value, $json['processState']);
        }

        // Test a case when a domain is not ready
        Queue::fake();
        $domain->status ^= Domain::STATUS_CONFIRMED;
        $domain->save();

        $response = $this->actingAs($john)->get("/api/v4/resources/{$resource->id}/status?refresh=1");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        if (\config('app.with_ldap')) {
            $this->assertFalse($json['isReady']);
            $this->assertFalse($json['isLdapReady']);
            $this->assertSame('resource-ldap-ready', $json['process'][1]['label']);
            $this->assertFalse($json['process'][1]['state']);
            $this->assertSame('Setup process has been pushed. Please wait.', $json['message']);
            $this->assertSame(ProcessState::Waiting->value, $json['processState']);

            Queue::assertPushed(CreateJob::class, 1);
        } else {
            $this->assertSame('Setup process finished successfully.', $json['message']);
            $this->assertTrue($json['isReady']);
            $this->assertSame(ProcessState::Done->value, $json['processState']);
        }
    }

    /**
     * Test ResourcesController::statusInfo()
     */
    public function testStatusInfo(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $resource = $this->getTestResource('resource-test@kolab.org');
        $resource->assignToWallet($john->wallets->first());
        $resource->status = Resource::STATUS_NEW | Resource::STATUS_ACTIVE;
        $resource->save();
        $domain = $this->getTestDomain('kolab.org');
        $domain->status |= Domain::STATUS_CONFIRMED;
        $domain->save();

        $result = ResourcesController::statusInfo($resource);

        $this->assertFalse($result['isDone']);
        $this->assertSame('resource-new', $result['process'][0]['label']);
        $this->assertTrue($result['process'][0]['state']);
        if (\config('app.with_ldap')) {
            $this->assertSame('resource-ldap-ready', $result['process'][1]['label']);
            $this->assertFalse($result['process'][1]['state']);
            $this->assertSame('resource-imap-ready', $result['process'][2]['label']);
            $this->assertFalse($result['process'][2]['state']);
        } else {
            $this->assertSame('resource-imap-ready', $result['process'][1]['label']);
            $this->assertFalse($result['process'][1]['state']);
        }
        $this->assertSame(ProcessState::Running, $result['processState']);

        $resource->created_at = Carbon::now()->subSeconds(181);
        $resource->save();

        $result = ResourcesController::statusInfo($resource);

        $this->assertSame(ProcessState::Failed, $result['processState']);

        $resource->status |= Resource::STATUS_LDAP_READY | Resource::STATUS_IMAP_READY;
        $resource->save();

        $result = ResourcesController::statusInfo($resource);

        $this->assertTrue($result['isDone']);
        $this->assertSame('resource-new', $result['process'][0]['label']);
        $this->assertTrue($result['process'][0]['state']);
        if (\config('app.with_ldap')) {
            $this->assertSame('resource-ldap-ready', $result['process'][1]['label']);
            $this->assertTrue($result['process'][1]['state']);
            $this->assertSame('resource-imap-ready', $result['process'][2]['label']);
            $this->assertTrue($result['process'][2]['state']);
        } else {
            $this->assertSame('resource-imap-ready', $result['process'][1]['label']);
            $this->assertTrue($result['process'][1]['state']);
        }
        $this->assertSame(ProcessState::Done, $result['processState']);
    }

    /**
     * Test resource creation (POST /api/v4/resources)
     */
    public function testStore(): void
    {
        Queue::fake();

        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');

        // Test unauth request
        $response = $this->post("/api/v4/resources", []);
        $response->assertStatus(401);

        // Test non-controller user
        $response = $this->actingAs($jack)->post("/api/v4/resources", []);
        $response->assertStatus(403);

        // Test empty request
        $response = $this->actingAs($john)->post("/api/v4/resources", []);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("The name field is required.", $json['errors']['name'][0]);
        $this->assertCount(2, $json);
        $this->assertCount(1, $json['errors']);

        // Test too long name
        $post = ['domain' => 'kolab.org', 'name' => str_repeat('A', 192)];
        $response = $this->actingAs($john)->post("/api/v4/resources", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertSame("The name may not be greater than 191 characters.", $json['errors']['name'][0]);
        $this->assertCount(1, $json['errors']);

        // Test successful resource creation
        $post['name'] = 'Test Resource';
        $response = $this->actingAs($john)->post("/api/v4/resources", $post);
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame('success', $json['status']);
        $this->assertSame("Resource created successfully.", $json['message']);
        $this->assertCount(2, $json);

        $resource = Resource::where('name', $post['name'])->first();
        $this->assertInstanceOf(Resource::class, $resource);
        $this->assertTrue($john->resources()->get()->contains($resource));

        // Test that a wallet controller can create a resource
        $ned = $this->getTestUser('ned@kolab.org');
        $resource->delete();

        $response = $this->actingAs($ned)->post("/api/v4/resources", $post);
        $json = $response->json();

        $response->assertStatus(200);
        $resource = Resource::where('name', $post['name'])->first();
        $this->assertTrue($john->resources()->get()->contains($resource));

        // Resource name must be unique within a domain
        $response = $this->actingAs($john)->post("/api/v4/resources", $post);
        $response->assertStatus(422);
        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertCount(1, $json['errors']);
        $this->assertSame("The specified name is not available.", $json['errors']['name'][0]);
    }

    /**
     * Test resource update (PUT /api/v4/resources/<resource>)
     */
    public function testUpdate(): void
    {
        Queue::fake();

        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        $resource = $this->getTestResource('resource-test@kolab.org');
        $resource->assignToWallet($john->wallets->first());

        // Test unauthorized update
        $response = $this->get("/api/v4/resources/{$resource->id}", []);
        $response->assertStatus(401);

        // Test unauthorized update
        $response = $this->actingAs($jack)->get("/api/v4/resources/{$resource->id}", []);
        $response->assertStatus(403);

        // Name change
        $post = [
            'name' => 'Test Res',
        ];

        $response = $this->actingAs($john)->put("/api/v4/resources/{$resource->id}", $post);
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame('success', $json['status']);
        $this->assertSame("Resource updated successfully.", $json['message']);
        $this->assertCount(2, $json);

        $resource->refresh();
        $this->assertSame($post['name'], $resource->name);
    }
}
