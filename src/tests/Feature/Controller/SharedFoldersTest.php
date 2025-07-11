<?php

namespace Tests\Feature\Controller;

use App\Domain;
use App\Http\Controllers\API\V4\SharedFoldersController;
use App\Jobs\SharedFolder\CreateJob;
use App\SharedFolder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SharedFoldersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestSharedFolder('folder-test@kolab.org');
        SharedFolder::where('name', 'like', 'Test_Folder')->forceDelete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestSharedFolder('folder-test@kolab.org');
        SharedFolder::where('name', 'like', 'Test_Folder')->forceDelete();

        parent::tearDown();
    }

    /**
     * Test resource deleting (DELETE /api/v4/resources/<id>)
     */
    public function testDestroy(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $folder = $this->getTestSharedFolder('folder-test@kolab.org');
        $folder->assignToWallet($john->wallets->first());

        // Test unauth access
        $response = $this->delete("api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(401);

        // Test non-existing folder
        $response = $this->actingAs($john)->delete("api/v4/shared-folders/abc");
        $response->assertStatus(404);

        // Test access to other user's folder
        $response = $this->actingAs($jack)->delete("api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(403);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("Access denied", $json['message']);
        $this->assertCount(2, $json);

        // Test removing a folder
        $response = $this->actingAs($john)->delete("api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("Shared folder deleted successfully.", $json['message']);
    }

    /**
     * Test shared folders listing (GET /api/v4/shared-folders)
     */
    public function testIndex(): void
    {
        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        // Test unauth access
        $response = $this->get("api/v4/shared-folders");
        $response->assertStatus(401);

        // Test a user with no shared folders
        $response = $this->actingAs($jack)->get("/api/v4/shared-folders");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(4, $json);
        $this->assertSame(0, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame("0 shared folders have been found.", $json['message']);
        $this->assertSame([], $json['list']);

        // Test a user with two shared folders
        $response = $this->actingAs($john)->get("/api/v4/shared-folders");
        $response->assertStatus(200);

        $json = $response->json();

        $folder = SharedFolder::where('name', 'Library')->first();
        $count = in_array('event', config('app.shared_folder_types')) ? 3 : 1;

        $this->assertCount(4, $json);
        $this->assertSame($count, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertSame("{$count} shared folders have been found.", $json['message']);
        $this->assertCount($count, $json['list']);
        $this->assertSame($folder->id, $json['list'][$count - 1]['id']);
        $this->assertSame($folder->email, $json['list'][$count - 1]['email']);
        $this->assertSame($folder->name, $json['list'][$count - 1]['name']);
        $this->assertSame($folder->type, $json['list'][$count - 1]['type']);
        $this->assertArrayHasKey('isDeleted', $json['list'][$count - 1]);
        $this->assertArrayHasKey('isActive', $json['list'][$count - 1]);
        $this->assertArrayHasKey('isImapReady', $json['list'][$count - 1]);
        if (\config('app.with_ldap')) {
            $this->assertArrayHasKey('isLdapReady', $json['list'][$count - 1]);
        }

        // Test that another wallet controller has access to shared folders
        $response = $this->actingAs($ned)->get("/api/v4/shared-folders");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(4, $json);
        $this->assertSame($count, $json['count']);
    }

    /**
     * Test shared folder config update (POST /api/v4/shared-folders/<folder>/config)
     */
    public function testSetConfig(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $folder = $this->getTestSharedFolder('folder-test@kolab.org');
        $folder->assignToWallet($john->wallets->first());

        // Test unknown resource id
        $post = ['acl' => ['john@kolab.org, full']];
        $response = $this->actingAs($john)->post("/api/v4/shared-folders/123/config", $post);
        $json = $response->json();

        $response->assertStatus(404);

        // Test access by user not being a wallet controller
        $response = $this->actingAs($jack)->post("/api/v4/shared-folders/{$folder->id}/config", $post);
        $json = $response->json();

        $response->assertStatus(403);

        $this->assertSame('error', $json['status']);
        $this->assertSame("Access denied", $json['message']);
        $this->assertCount(2, $json);

        // Test some invalid data
        $post = ['test' => 1];
        $response = $this->actingAs($john)->post("/api/v4/shared-folders/{$folder->id}/config", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertCount(1, $json['errors']);
        $this->assertSame('The requested configuration parameter is not supported.', $json['errors']['test']);

        $folder->refresh();

        $this->assertNull($folder->getSetting('test'));
        $this->assertNull($folder->getSetting('acl'));

        // Test some valid data
        $post = ['acl' => ['john@kolab.org, full']];
        $response = $this->actingAs($john)->post("/api/v4/shared-folders/{$folder->id}/config", $post);

        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Shared folder settings updated successfully.", $json['message']);

        $this->assertSame(['acl' => $post['acl']], $folder->fresh()->getConfig());

        // Test input validation
        $post = ['acl' => ['john@kolab.org, full', 'test, full']];
        $response = $this->actingAs($john)->post("/api/v4/shared-folders/{$folder->id}/config", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('error', $json['status']);
        $this->assertCount(1, $json['errors']);
        $this->assertCount(1, $json['errors']['acl']);
        $this->assertSame(
            "The specified email address is invalid.",
            $json['errors']['acl'][1]
        );

        $this->assertSame(['acl' => ['john@kolab.org, full']], $folder->fresh()->getConfig());
    }

    /**
     * Test fetching shared folder data/profile (GET /api/v4/shared-folders/<folder>)
     */
    public function testShow(): void
    {
        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        $folder = $this->getTestSharedFolder('folder-test@kolab.org');
        $folder->assignToWallet($john->wallets->first());
        $folder->setSetting('acl', '["anyone, full"]');
        $folder->setAliases(['folder-alias@kolab.org']);

        // Test unauthenticated access
        $response = $this->get("/api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(401);

        // Test unauthorized access to a shared folder of another user
        $response = $this->actingAs($jack)->get("/api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(403);

        // John: Account owner - non-existing folder
        $response = $this->actingAs($john)->get("/api/v4/shared-folders/abc");
        $response->assertStatus(404);

        // John: Account owner
        $response = $this->actingAs($john)->get("/api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($folder->id, $json['id']);
        $this->assertSame($folder->email, $json['email']);
        $this->assertSame($folder->name, $json['name']);
        $this->assertSame($folder->type, $json['type']);
        $this->assertSame(['folder-alias@kolab.org'], $json['aliases']);
        $this->assertTrue(!empty($json['statusInfo']));
        $this->assertArrayHasKey('isDeleted', $json);
        $this->assertArrayHasKey('isActive', $json);
        $this->assertArrayHasKey('isImapReady', $json);
        if (\config('app.with_ldap')) {
            $this->assertArrayHasKey('isLdapReady', $json);
        }
        $this->assertSame(['acl' => ['anyone, full']], $json['config']);
        $this->assertCount(1, $json['skus']);
    }

    /**
     * Test fetching SKUs list for a shared folder (GET /shared-folders/<id>/skus)
     */
    public function testSkus(): void
    {
        Queue::fake();

        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');

        $folder = $this->getTestSharedFolder('folder-test@kolab.org');
        $folder->assignToWallet($john->wallets->first());

        // Unauth access not allowed
        $response = $this->get("api/v4/shared-folders/{$folder->id}/skus");
        $response->assertStatus(401);

        // Unauthorized access not allowed
        $response = $this->actingAs($jack)->get("api/v4/shared-folders/{$folder->id}/skus");
        $response->assertStatus(403);

        // Non-existing folder
        $response = $this->actingAs($john)->get("api/v4/shared-folders/non-existing/skus");
        $response->assertStatus(404);

        $response = $this->actingAs($john)->get("api/v4/shared-folders/{$folder->id}/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(1, $json);
        $this->assertSkuElement('shared-folder', $json[0], [
            'prio' => 0,
            'type' => 'sharedFolder',
            'handler' => 'SharedFolder',
            'enabled' => true,
            'readonly' => true,
        ]);
    }

    /**
     * Test fetching a shared folder status (GET /api/v4/shared-folders/<folder>/status)
     * and forcing setup process update (?refresh=1)
     */
    public function testStatus(): void
    {
        Queue::fake();

        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');

        $folder = $this->getTestSharedFolder('folder-test@kolab.org');
        $folder->assignToWallet($john->wallets->first());

        // Test unauthorized access
        $response = $this->get("/api/v4/shared-folders/abc/status");
        $response->assertStatus(401);

        // Test unauthorized access
        $response = $this->actingAs($jack)->get("/api/v4/shared-folders/{$folder->id}/status");
        $response->assertStatus(403);

        $folder->status = SharedFolder::STATUS_NEW | SharedFolder::STATUS_ACTIVE;
        $folder->save();

        // Get resource status
        $response = $this->actingAs($john)->get("/api/v4/shared-folders/{$folder->id}/status");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertFalse($json['isImapReady']);
        $this->assertFalse($json['isReady']);
        $this->assertFalse($json['isDeleted']);
        $this->assertTrue($json['isActive']);
        $this->assertSame('running', $json['processState']);
        $this->assertTrue(empty($json['status']));
        $this->assertTrue(empty($json['message']));
        $this->assertSame('shared-folder-new', $json['process'][0]['label']);
        $this->assertTrue($json['process'][0]['state']);
        if (\config('app.with_ldap')) {
            $this->assertFalse($json['isLdapReady']);
            $this->assertSame('shared-folder-ldap-ready', $json['process'][1]['label']);
            $this->assertFalse($json['process'][1]['state']);
        }

        // Make sure the domain is confirmed (other test might unset that status)
        $domain = $this->getTestDomain('kolab.org');
        $domain->status |= Domain::STATUS_CONFIRMED;
        $domain->save();
        $folder->status |= SharedFolder::STATUS_IMAP_READY;
        $folder->save();

        // Now "reboot" the process
        Queue::fake();
        $response = $this->actingAs($john)->get("/api/v4/shared-folders/{$folder->id}/status?refresh=1");
        $response->assertStatus(200);

        $json = $response->json();

        if (config('app.with_imap')) {
            $this->assertTrue($json['isImapReady']);
        }

        if (\config('app.with_ldap')) {
            $this->assertFalse($json['isReady']);
            $this->assertFalse($json['isLdapReady']);
            $this->assertSame('shared-folder-ldap-ready', $json['process'][1]['label']);
            $this->assertFalse($json['process'][1]['state']);
            $this->assertSame('shared-folder-imap-ready', $json['process'][2]['label']);
            $this->assertTrue($json['process'][2]['state']);
            $this->assertSame('Setup process has been pushed. Please wait.', $json['message']);
            $this->assertSame('waiting', $json['processState']);

            Queue::assertPushed(CreateJob::class, 1);
        } else {
            $this->assertTrue($json['isReady']);
            $this->assertSame('shared-folder-imap-ready', $json['process'][1]['label']);
            $this->assertTrue($json['process'][1]['state']);
            $this->assertSame('Setup process finished successfully.', $json['message']);
            $this->assertSame('done', $json['processState']);
        }
        $this->assertSame('success', $json['status']);

        // Test a case when a domain is not ready
        Queue::fake();
        $domain->status ^= Domain::STATUS_CONFIRMED;
        $domain->save();

        $response = $this->actingAs($john)->get("/api/v4/shared-folders/{$folder->id}/status?refresh=1");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertTrue($json['isImapReady']);
        $this->assertSame('success', $json['status']);
        if (\config('app.with_ldap')) {
            $this->assertFalse($json['isReady']);
            $this->assertFalse($json['isLdapReady']);
            $this->assertSame('shared-folder-ldap-ready', $json['process'][1]['label']);
            $this->assertFalse($json['process'][1]['state']);
            $this->assertSame('Setup process has been pushed. Please wait.', $json['message']);

            Queue::assertPushed(CreateJob::class, 1);
        } else {
            $this->assertTrue($json['isReady']);
            $this->assertSame('shared-folder-imap-ready', $json['process'][1]['label']);
            $this->assertTrue($json['process'][1]['state']);
            $this->assertSame('done', $json['processState']);
        }
    }

    /**
     * Test SharedFoldersController::statusInfo()
     */
    public function testStatusInfo(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $folder = $this->getTestSharedFolder('folder-test@kolab.org');
        $folder->assignToWallet($john->wallets->first());
        $folder->status = SharedFolder::STATUS_NEW | SharedFolder::STATUS_ACTIVE;
        $folder->save();
        $domain = $this->getTestDomain('kolab.org');
        $domain->status |= Domain::STATUS_CONFIRMED;
        $domain->save();

        $result = SharedFoldersController::statusInfo($folder);

        $this->assertFalse($result['isDone']);
        $this->assertSame('running', $result['processState']);
        $this->assertSame('shared-folder-new', $result['process'][0]['label']);
        $this->assertTrue($result['process'][0]['state']);
        if (\config('app.with_ldap')) {
            $this->assertSame('shared-folder-ldap-ready', $result['process'][1]['label']);
            $this->assertFalse($result['process'][1]['state']);
        }

        $folder->created_at = Carbon::now()->subSeconds(181);
        $folder->save();

        $result = SharedFoldersController::statusInfo($folder);

        $this->assertSame('failed', $result['processState']);

        $folder->status |= SharedFolder::STATUS_LDAP_READY | SharedFolder::STATUS_IMAP_READY;
        $folder->save();

        $result = SharedFoldersController::statusInfo($folder);

        $this->assertTrue($result['isDone']);
        $this->assertSame('done', $result['processState']);
        $this->assertSame('shared-folder-new', $result['process'][0]['label']);
        $this->assertTrue($result['process'][0]['state']);
        if (\config('app.with_ldap')) {
            $this->assertSame('shared-folder-ldap-ready', $result['process'][1]['label']);
            $this->assertTrue($result['process'][1]['state']);
            $this->assertSame('shared-folder-imap-ready', $result['process'][2]['label']);
            $this->assertTrue($result['process'][2]['state']);
        } else {
            $this->assertSame('shared-folder-imap-ready', $result['process'][1]['label']);
            $this->assertTrue($result['process'][1]['state']);
        }
    }

    /**
     * Test shared folder creation (POST /api/v4/shared-folders)
     */
    public function testStore(): void
    {
        Queue::fake();

        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');

        // Test unauth request
        $response = $this->post("/api/v4/shared-folders", []);
        $response->assertStatus(401);

        // Test non-controller user
        $response = $this->actingAs($jack)->post("/api/v4/shared-folders", []);
        $response->assertStatus(403);

        // Test empty request
        $response = $this->actingAs($john)->post("/api/v4/shared-folders", []);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("The name field is required.", $json['errors']['name'][0]);
        $this->assertSame("The type field is required.", $json['errors']['type'][0]);
        $this->assertCount(2, $json);
        $this->assertCount(2, $json['errors']);

        // Test too long name, invalid alias domain
        $post = [
            'domain' => 'kolab.org',
            'name' => str_repeat('A', 192),
            'type' => 'unknown',
            'aliases' => ['folder-alias@unknown.org'],
        ];

        $response = $this->actingAs($john)->post("/api/v4/shared-folders", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertSame(["The name may not be greater than 191 characters."], $json['errors']['name']);
        $this->assertSame(["The specified type is invalid."], $json['errors']['type']);
        $this->assertSame(["The specified domain is invalid."], $json['errors']['aliases']);
        $this->assertCount(3, $json['errors']);

        \config(['app.shared_folder_types' => ['mail', 'event']]);

        // Test successful folder creation
        $post['name'] = 'Test Folder';
        $post['type'] = 'event';
        $post['aliases'] = [];

        $response = $this->actingAs($john)->post("/api/v4/shared-folders", $post);
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame('success', $json['status']);
        $this->assertSame("Shared folder created successfully.", $json['message']);
        $this->assertCount(2, $json);

        $folder = SharedFolder::where('name', $post['name'])->first();
        $this->assertInstanceOf(SharedFolder::class, $folder);
        $this->assertSame($post['type'], $folder->type);
        $this->assertTrue($john->sharedFolders()->get()->contains($folder));
        $this->assertSame([], $folder->aliases()->pluck('alias')->all());

        // Shared folder name must be unique within a domain
        $post['type'] = 'mail';
        $response = $this->actingAs($john)->post("/api/v4/shared-folders", $post);
        $response->assertStatus(422);
        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertCount(1, $json['errors']);
        $this->assertSame("The specified name is not available.", $json['errors']['name'][0]);

        $folder->forceDelete();

        // Test successful folder creation with aliases
        $post['name'] = 'Test Folder';
        $post['type'] = 'mail';
        $post['aliases'] = ['folder-alias@kolab.org'];
        $response = $this->actingAs($john)->post("/api/v4/shared-folders", $post);
        $json = $response->json();

        $response->assertStatus(200);

        $folder = SharedFolder::where('name', $post['name'])->first();
        $this->assertSame(['folder-alias@kolab.org'], $folder->aliases()->pluck('alias')->all());

        $folder->forceDelete();

        // Test handling subfolders and lmtp alias email
        $post['name'] = 'Test/Folder';
        $post['type'] = 'mail';
        $post['aliases'] = ['shared+shared/Test/Folder@kolab.org'];
        $response = $this->actingAs($john)->post("/api/v4/shared-folders", $post);
        $json = $response->json();

        $response->assertStatus(200);

        $folder = SharedFolder::where('name', $post['name'])->first();
        $this->assertSame(['shared+shared/Test/Folder@kolab.org'], $folder->aliases()->pluck('alias')->all());
    }

    /**
     * Test shared folder update (PUT /api/v4/shared-folders/<folder)
     */
    public function testUpdate(): void
    {
        Queue::fake();

        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        $folder = $this->getTestSharedFolder('folder-test@kolab.org');
        $folder->assignToWallet($john->wallets->first());

        // Test unauthorized update
        $response = $this->get("/api/v4/shared-folders/{$folder->id}", []);
        $response->assertStatus(401);

        // Test unauthorized update
        $response = $this->actingAs($jack)->get("/api/v4/shared-folders/{$folder->id}", []);
        $response->assertStatus(403);

        // Name change
        $post = [
            'name' => 'Test Res',
        ];

        $response = $this->actingAs($john)->put("/api/v4/shared-folders/{$folder->id}", $post);
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame('success', $json['status']);
        $this->assertSame("Shared folder updated successfully.", $json['message']);
        $this->assertCount(2, $json);

        $folder->refresh();
        $this->assertSame($post['name'], $folder->name);

        // Aliases with error
        $post['aliases'] = ['folder-alias1@kolab.org', 'folder-alias2@unknown.com'];

        $response = $this->actingAs($john)->put("/api/v4/shared-folders/{$folder->id}", $post);
        $json = $response->json();

        $response->assertStatus(422);

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json);
        $this->assertCount(1, $json['errors']);
        $this->assertCount(1, $json['errors']['aliases']);
        $this->assertSame("The specified domain is invalid.", $json['errors']['aliases'][1]);
        $this->assertSame([], $folder->aliases()->pluck('alias')->all());

        // Aliases with success expected
        $post['aliases'] = ['folder-alias1@kolab.org', 'folder-alias2@kolab.org'];

        $response = $this->actingAs($john)->put("/api/v4/shared-folders/{$folder->id}", $post);
        $json = $response->json();

        $response->assertStatus(200);

        $this->assertSame('success', $json['status']);
        $this->assertSame("Shared folder updated successfully.", $json['message']);
        $this->assertCount(2, $json);
        $this->assertSame($post['aliases'], $folder->aliases()->pluck('alias')->all());

        // All aliases removal
        $post['aliases'] = [];

        $response = $this->actingAs($john)->put("/api/v4/shared-folders/{$folder->id}", $post);
        $response->assertStatus(200);

        $this->assertSame($post['aliases'], $folder->aliases()->pluck('alias')->all());
    }
}
