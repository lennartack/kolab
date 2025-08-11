<?php

namespace Tests\Feature\Controller;

use App\Domain;
use App\Group;
use App\Http\Controllers\API\V4\GroupsController;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GroupsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestGroup('group-test@kolab.org');
        $this->deleteTestGroup('group-test2@kolab.org');
        $this->deleteTestGroup('deleted@kolab.org');
        $this->deleteTestUser('deleted@kolab.org');
    }

    protected function tearDown(): void
    {
        $this->deleteTestGroup('group-test@kolab.org');
        $this->deleteTestGroup('group-test2@kolab.org');
        $this->deleteTestGroup('deleted@kolab.org');
        $this->deleteTestUser('deleted@kolab.org');

        parent::tearDown();
    }

    /**
     * Test group deleting (DELETE /api/v4/groups/<id>)
     */
    public function testDestroy(): void
    {
        // First create some groups to delete
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($john->wallets->first());

        // Test unauth access
        $response = $this->delete("api/v4/groups/{$group->id}");
        $response->assertStatus(401);

        // Test non-existing group
        $response = $this->actingAs($john)->delete("api/v4/groups/abc");
        $response->assertStatus(404);

        // Test access to other user's group
        $response = $this->actingAs($jack)->delete("api/v4/groups/{$group->id}");
        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("Access denied", $json['message']);
        $this->assertCount(2, $json);

        // Test removing a group
        $response = $this->actingAs($john)->delete("api/v4/groups/{$group->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("Distribution list deleted successfully.", $json['message']);
    }

    /**
     * Test groups listing (GET /api/v4/groups)
     */
    public function testIndex(): void
    {
        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($john->wallets->first());

        // Test unauth access
        $response = $this->get("api/v4/groups");
        $response->assertStatus(401);

        // Test a user with no groups
        $response = $this->actingAs($jack)->get("/api/v4/groups");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(4, $json);
        $this->assertSame(0, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame("0 distribution lists have been found.", $json['message']);
        $this->assertSame([], $json['list']);

        // Test a user with a single group
        $response = $this->actingAs($john)->get("/api/v4/groups");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(4, $json);
        $this->assertSame(1, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame("1 distribution lists have been found.", $json['message']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($group->id, $json['list'][0]['id']);
        $this->assertSame($group->email, $json['list'][0]['email']);
        $this->assertSame($group->name, $json['list'][0]['name']);
        $this->assertArrayHasKey('isDeleted', $json['list'][0]);
        $this->assertArrayHasKey('isSuspended', $json['list'][0]);
        $this->assertArrayHasKey('isActive', $json['list'][0]);
        if (\config('app.with_ldap')) {
            $this->assertArrayHasKey('isLdapReady', $json['list'][0]);
        }

        // Test that another wallet controller has access to groups
        $response = $this->actingAs($ned)->get("/api/v4/groups");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(4, $json);
        $this->assertSame(1, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame("1 distribution lists have been found.", $json['message']);
        $this->assertCount(1, $json['list']);
        $this->assertSame($group->email, $json['list'][0]['email']);
    }

    /**
     * Test group config update (POST /api/v4/groups/<group>/config)
     */
    public function testSetConfig(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($john->wallets->first());

        // Test unknown group id
        $post = ['sender_policy' => []];
        $response = $this->actingAs($john)->post("/api/v4/groups/123/config", $post);
        $json = $response->json();

        $response->assertStatus(404);

        // Test access by user not being a wallet controller
        $post = ['sender_policy' => []];
        $response = $this->actingAs($jack)->post("/api/v4/groups/{$group->id}/config", $post);
        $json = $response->json();

        $response->assertStatus(403);

        $this->assertSame('error', $json['status']);
        $this->assertSame("Access denied", $json['message']);
        $this->assertCount(2, $json);

        // Test some invalid data
        $post = ['test' => 1];
        $response = $this->actingAs($john)->post("/api/v4/groups/{$group->id}/config", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertCount(1, $json['errors']);
        $this->assertSame('The requested configuration parameter is not supported.', $json['errors']['test']);

        $group->refresh();

        $this->assertNull($group->getSetting('test'));
        $this->assertNull($group->getSetting('sender_policy'));

        // Test some valid data
        $post = ['sender_policy' => ['domain.com']];
        $response = $this->actingAs($john)->post("/api/v4/groups/{$group->id}/config", $post);

        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Distribution list settings updated successfully.', $json['message']);

        $this->assertSame(['sender_policy' => $post['sender_policy']], $group->fresh()->getConfig());

        // Test input validation
        $post = ['sender_policy' => [5]];
        $response = $this->actingAs($john)->post("/api/v4/groups/{$group->id}/config", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame(
            'The entry format is invalid. Expected an email, domain, or part of it.',
            $json['errors']['sender_policy'][0]
        );

        $this->assertSame(['sender_policy' => ['domain.com']], $group->fresh()->getConfig());
    }

    /**
     * Test fetching group data/profile (GET /api/v4/groups/<group-id>)
     */
    public function testShow(): void
    {
        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($john->wallets->first());
        $group->setSetting('sender_policy', '["test"]');

        // Test unauthorized access to a profile of other user
        $response = $this->get("/api/v4/groups/{$group->id}");
        $response->assertStatus(401);

        // Test unauthorized access to a group of another user
        $response = $this->actingAs($jack)->get("/api/v4/groups/{$group->id}");
        $response->assertStatus(403);

        // John: Group owner - non-existing group
        $response = $this->actingAs($john)->get("/api/v4/groups/abc");
        $response->assertStatus(404);

        // John: Group owner
        $response = $this->actingAs($john)->get("/api/v4/groups/{$group->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($group->id, $json['id']);
        $this->assertSame($group->email, $json['email']);
        $this->assertSame($group->name, $json['name']);
        $this->assertSame($group->members, $json['members']);
        $this->assertTrue(!empty($json['statusInfo']));
        $this->assertArrayHasKey('isDeleted', $json);
        $this->assertArrayHasKey('isSuspended', $json);
        $this->assertArrayHasKey('isActive', $json);
        if (\config('app.with_ldap')) {
            $this->assertArrayHasKey('isLdapReady', $json);
        }
        $this->assertSame(['sender_policy' => ['test']], $json['config']);
        $this->assertCount(1, $json['skus']);
    }

    /**
     * Test fetching SKUs list for a group (GET /groups/<id>/skus)
     */
    public function testSkus(): void
    {
        Queue::fake();

        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');

        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($john->wallets->first());

        // Unauth access not allowed
        $response = $this->get("api/v4/groups/{$group->id}/skus");
        $response->assertStatus(401);

        // Unauthorized access not allowed
        $response = $this->actingAs($jack)->get("api/v4/groups/{$group->id}/skus");
        $response->assertStatus(403);

        $response = $this->actingAs($john)->get("api/v4/groups/{$group->id}/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(1, $json);
        $this->assertSkuElement('group', $json[0], [
            'prio' => 0,
            'type' => 'group',
            'handler' => 'Group',
            'enabled' => true,
            'readonly' => true,
        ]);
    }

    /**
     * Test fetching group status (GET /api/v4/groups/<group-id>/status)
     * and forcing setup process update (?refresh=1)
     */
    public function testStatus(): void
    {
        Queue::fake();

        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');

        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($john->wallets->first());

        // Test unauthorized access
        $response = $this->get("/api/v4/groups/abc/status");
        $response->assertStatus(401);

        // Test unauthorized access
        $response = $this->actingAs($jack)->get("/api/v4/groups/{$group->id}/status");
        $response->assertStatus(403);

        $group->status = Group::STATUS_NEW | Group::STATUS_ACTIVE;
        $group->save();

        // Get group status
        $response = $this->actingAs($john)->get("/api/v4/groups/{$group->id}/status");
        $response->assertStatus(200);

        $json = $response->json();

        if (\config('app.with_ldap')) {
            $this->assertFalse($json['isLdapReady']);
            $this->assertFalse($json['isReady']);
            $this->assertCount(6, $json['process']);
            $this->assertSame('distlist-ldap-ready', $json['process'][1]['label']);
            $this->assertFalse($json['process'][1]['state']);
        } else {
            $this->assertCount(4, $json['process']);
            $this->assertTrue($json['isReady']);
        }
        $this->assertFalse($json['isSuspended']);
        $this->assertTrue($json['isActive']);
        $this->assertFalse($json['isDeleted']);
        $this->assertSame('distlist-new', $json['process'][0]['label']);
        $this->assertTrue($json['process'][0]['state']);
        $this->assertTrue(empty($json['status']));
        $this->assertTrue(empty($json['message']));

        // Make sure the domain is confirmed (other test might unset that status)
        $domain = $this->getTestDomain('kolab.org');
        $domain->status |= Domain::STATUS_CONFIRMED;
        $domain->save();

        // Now "reboot" the process and  the group
        $response = $this->actingAs($john)->get("/api/v4/groups/{$group->id}/status?refresh=1");
        $response->assertStatus(200);

        $json = $response->json();

        if (\config('app.with_ldap')) {
            $this->assertTrue($json['isLdapReady']);
            $this->assertCount(6, $json['process']);
            $this->assertSame('distlist-ldap-ready', $json['process'][1]['label']);
            $this->assertTrue($json['process'][1]['state']);
        }
        $this->assertTrue($json['isReady']);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Setup process finished successfully.', $json['message']);

        // Test a case when a domain is not ready
        $domain->status ^= Domain::STATUS_CONFIRMED;
        $domain->save();

        $response = $this->actingAs($john)->get("/api/v4/groups/{$group->id}/status?refresh=1");
        $response->assertStatus(200);

        $json = $response->json();

        if (\config('app.with_ldap')) {
            $this->assertTrue($json['isLdapReady']);
            $this->assertCount(6, $json['process']);
            $this->assertSame('distlist-ldap-ready', $json['process'][1]['label']);
            $this->assertTrue($json['process'][1]['state']);
        }
        $this->assertTrue($json['isReady']);
        $this->assertSame('success', $json['status']);
        $this->assertSame('Setup process finished successfully.', $json['message']);
    }

    /**
     * Test GroupsController::statusInfo()
     */
    public function testStatusInfo(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($john->wallets->first());
        $group->status = Group::STATUS_NEW | Group::STATUS_ACTIVE;
        $group->save();

        $result = GroupsController::statusInfo($group);

        if (\config('app.with_ldap')) {
            $this->assertFalse($result['isDone']);
            $this->assertCount(6, $result['process']);
            $this->assertSame('distlist-new', $result['process'][0]['label']);
            $this->assertTrue($result['process'][0]['state']);
            $this->assertSame('distlist-ldap-ready', $result['process'][1]['label']);
            $this->assertFalse($result['process'][1]['state']);
            $this->assertSame('running', $result['processState']);
        } else {
            $this->assertTrue($result['isDone']);
            $this->assertSame('done', $result['processState']);
            $this->markTestSkipped();
        }

        $group->created_at = Carbon::now()->subSeconds(181);
        $group->save();

        $result = GroupsController::statusInfo($group);

        $this->assertSame('failed', $result['processState']);

        $group->status |= Group::STATUS_LDAP_READY;
        $group->save();

        $result = GroupsController::statusInfo($group);

        $this->assertTrue($result['isDone']);
        $this->assertCount(6, $result['process']);
        $this->assertSame('distlist-new', $result['process'][0]['label']);
        $this->assertTrue($result['process'][0]['state']);
        $this->assertSame('distlist-ldap-ready', $result['process'][1]['label']);
        $this->assertTrue($result['process'][2]['state']);
        $this->assertSame('done', $result['processState']);
    }

    /**
     * Test group creation (POST /api/v4/groups)
     */
    public function testStore(): void
    {
        Queue::fake();

        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');

        // Test unauth request
        $response = $this->post("/api/v4/groups", []);
        $response->assertStatus(401);

        // Test non-controller user
        $response = $this->actingAs($jack)->post("/api/v4/groups", []);
        $response->assertStatus(403);

        // Test empty request
        $response = $this->actingAs($john)->post("/api/v4/groups", []);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("The email field is required.", $json['errors']['email']);
        $this->assertSame("At least one recipient is required.", $json['errors']['members']);
        $this->assertSame("The name field is required.", $json['errors']['name'][0]);
        $this->assertCount(2, $json);
        $this->assertCount(3, $json['errors']);

        // Test missing members and name
        $post = ['email' => 'group-test@kolab.org'];
        $response = $this->actingAs($john)->post("/api/v4/groups", $post);
        $json = $response->json();

        $response->assertStatus(422);

        $this->assertSame('error', $json['status']);
        $this->assertSame("At least one recipient is required.", $json['errors']['members']);
        $this->assertSame("The name field is required.", $json['errors']['name'][0]);
        $this->assertCount(2, $json);
        $this->assertCount(2, $json['errors']);

        // Test invalid email and too long name
        $post = ['email' => 'invalid', 'name' => str_repeat('A', 192)];
        $response = $this->actingAs($john)->post("/api/v4/groups", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertSame("The specified email is invalid.", $json['errors']['email']);
        $this->assertSame("The name may not be greater than 191 characters.", $json['errors']['name'][0]);
        $this->assertCount(3, $json['errors']);

        // Test successful group creation
        $post = [
            'name' => 'Test Group',
            'email' => 'group-test@kolab.org',
            'members' => ['test1@domain.tld', 'test2@domain.tld'],
        ];

        $response = $this->actingAs($john)->post("/api/v4/groups", $post);
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame('success', $json['status']);
        $this->assertSame("Distribution list created successfully.", $json['message']);
        $this->assertCount(2, $json);

        $group = Group::where('email', 'group-test@kolab.org')->first();
        $this->assertInstanceOf(Group::class, $group);
        $this->assertSame($post['email'], $group->email);
        $this->assertSame($post['members'], $group->members);
        $this->assertTrue($john->groups()->get()->contains($group));

        // Group name must be unique within a domain
        $post['email'] = 'group-test2@kolab.org';
        $post['members'] = ['test1@domain.tld'];

        $response = $this->actingAs($john)->post("/api/v4/groups", $post);
        $response->assertStatus(422);
        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertCount(1, $json['errors']);
        $this->assertSame("The specified name is not available.", $json['errors']['name'][0]);

        // Test a group email that belongs to a deleted user
        $user = $this->getTestUser('deleted@kolab.org');
        $user->delete();

        $post = [
            'name' => 'Test Group 2',
            'email' => $user->email,
            'members' => ['test3@domain.tld'],
        ];

        $response = $this->actingAs($john)->post("/api/v4/groups", $post);
        $response->assertStatus(200);

        $this->assertCount(0, User::withTrashed()->where('email', $post['email'])->get());

        // Test a group email that belongs to a deleted group
        $group = Group::where('email', $post['email'])->first();
        $group->delete();

        $response = $this->actingAs($john)->post("/api/v4/groups", $post);
        $response->assertStatus(200);

        $this->assertCount(1, Group::where('email', $post['email'])->get());

        // Test that wallet controllers can create groups too
        $ned = $this->getTestUser('ned@kolab.org');
        Group::where('email', $post['email'])->delete();

        $response = $this->actingAs($ned)->post("/api/v4/groups", $post);
        $response->assertStatus(200);

        $group = Group::where('email', $post['email'])->first();
        $this->assertSame($john->wallets->first()->id, $group->wallet()->id);
    }

    /**
     * Test group update (PUT /api/v4/groups/<group-id>)
     */
    public function testUpdate(): void
    {
        Queue::fake();

        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($john->wallets->first());

        // Test unauthorized update
        $response = $this->get("/api/v4/groups/{$group->id}", []);
        $response->assertStatus(401);

        // Test unauthorized update
        $response = $this->actingAs($jack)->get("/api/v4/groups/{$group->id}", []);
        $response->assertStatus(403);

        // Test updating - missing members
        $response = $this->actingAs($john)->put("/api/v4/groups/{$group->id}", []);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("At least one recipient is required.", $json['errors']['members']);
        $this->assertCount(2, $json);

        // Test some invalid data
        $post = ['members' => ['test@domain.tld', 'invalid']];
        $response = $this->actingAs($john)->put("/api/v4/groups/{$group->id}", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertSame('The specified email address is invalid.', $json['errors']['members'][1]);

        // Valid data - members and name changed
        $post = [
            'name' => 'Test Gr',
            'members' => ['member1@test.domain', 'member2@test.domain'],
        ];

        $response = $this->actingAs($john)->put("/api/v4/groups/{$group->id}", $post);
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame('success', $json['status']);
        $this->assertSame("Distribution list updated successfully.", $json['message']);
        $this->assertCount(2, $json);

        $group->refresh();

        $this->assertSame($post['name'], $group->name);
        $this->assertSame($post['members'], $group->members);
    }

    /**
     * Group email address validation.
     */
    public function testValidateGroupEmail(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $group = $this->getTestGroup('group-test@kolab.org');

        // Invalid email
        $result = GroupsController::validateGroupEmail('', $john);
        $this->assertSame("The email field is required.", $result);

        $result = GroupsController::validateGroupEmail('kolab.org', $john);
        $this->assertSame("The specified email is invalid.", $result);

        $result = GroupsController::validateGroupEmail('.@kolab.org', $john);
        $this->assertSame("The specified email is invalid.", $result);

        $result = GroupsController::validateGroupEmail('test123456@localhost', $john);
        $this->assertSame("The specified domain is invalid.", $result);

        $result = GroupsController::validateGroupEmail('test123456@unknown-domain.org', $john);
        $this->assertSame("The specified domain is invalid.", $result);

        // forbidden public domain
        $result = GroupsController::validateGroupEmail('testtest@kolabnow.com', $john);
        $this->assertSame("The specified domain is not available.", $result);

        // existing alias
        $result = GroupsController::validateGroupEmail('jack.daniels@kolab.org', $john);
        $this->assertSame("The specified email is not available.", $result);

        // existing user
        $result = GroupsController::validateGroupEmail('ned@kolab.org', $john);
        $this->assertSame("The specified email is not available.", $result);

        // existing group
        $result = GroupsController::validateGroupEmail('group-test@kolab.org', $john);
        $this->assertSame("The specified email is not available.", $result);

        // valid
        $result = GroupsController::validateGroupEmail('admin@kolab.org', $john);
        $this->assertNull($result);
    }

    /**
     * Group member email address validation.
     */
    public function testValidateMemberEmail(): void
    {
        $john = $this->getTestUser('john@kolab.org');

        // Invalid format
        $result = GroupsController::validateMemberEmail('kolab.org', $john);
        $this->assertSame("The specified email address is invalid.", $result);

        $result = GroupsController::validateMemberEmail('.@kolab.org', $john);
        $this->assertSame("The specified email address is invalid.", $result);

        $result = GroupsController::validateMemberEmail('test123456@localhost', $john);
        $this->assertSame("The specified email address is invalid.", $result);

        // Test local non-existing user
        $result = GroupsController::validateMemberEmail('unknown@kolab.org', $john);
        $this->assertSame("The specified email address does not exist.", $result);

        // Test local existing user
        $result = GroupsController::validateMemberEmail('ned@kolab.org', $john);
        $this->assertNull($result);

        // Test existing user, but not in the same account
        $result = GroupsController::validateMemberEmail('jeroen@jeroen.jeroen', $john);
        $this->assertNull($result);

        // Valid address
        $result = GroupsController::validateMemberEmail('test@google.com', $john);
        $this->assertNull($result);
    }
}
