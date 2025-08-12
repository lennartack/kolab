<?php

namespace Tests\Feature\Backends;

use App\Backends\IMAP;
use App\Backends\IMAP\Exceptions\MailboxExistsException;
use App\Sku;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IMAPTest extends TestCase
{
    private $imap;
    private $user;
    private $user2;
    private $group;
    private $resource;
    private $folder;

    protected function setUp(): void
    {
        parent::setUp();

        if (!\config('app.with_imap')) {
            $this->markTestSkipped();
        }
    }

    protected function tearDown(): void
    {
        if ($this->imap) {
            $this->imap->closeConnection();
            $this->imap = null;
        }

        if ($this->user) {
            $this->deleteTestUser($this->user->email, true);
        }
        if ($this->user2) {
            $this->deleteTestUser($this->user2->email, true);
        }
        if ($this->group) {
            $this->deleteTestGroup($this->group->email, true);
        }
        if ($this->resource) {
            $this->deleteTestResource($this->resource->email, true);
        }
        if ($this->folder) {
            $this->deleteTestSharedFolder($this->folder->email, true);
        }

        parent::tearDown();
    }

    /**
     * Test aclCleanup()
     *
     * @group imap
     */
    public function testAclCleanup(): void
    {
        Queue::fake();

        $ts = str_replace('.', '', (string) microtime(true));
        $this->user = $user = $this->getTestUser("test-{$ts}@kolab.org", [], true);
        $this->group = $group = $this->getTestGroup("test-group-{$ts}@kolab.org");

        // First, set some ACLs that we'll expect to be removed later
        $imap = $this->getImap();

        $this->assertTrue($imap->setACL('user/john@kolab.org', $user->email, 'lrs'));
        $this->assertTrue($imap->setACL('shared/Resources/Conference Room #1@kolab.org', $user->email, 'lrs'));
        /*
                $this->assertTrue($imap->setACL('user/john@kolab.org', $group->name, 'lrs'));
                $this->assertTrue($imap->setACL('shared/Resources/Conference Room #1@kolab.org', $group->name, 'lrs'));
        */
        // Cleanup ACL of a user
        IMAP::aclCleanup($user->email);

        $acl = $imap->getACL('user/john@kolab.org');
        $this->assertTrue(is_array($acl) && !isset($acl[$user->email]));
        $acl = $imap->getACL('shared/Resources/Conference Room #1@kolab.org');
        $this->assertTrue(is_array($acl) && !isset($acl[$user->email]));

        /*
                // Cleanup ACL of a group
                IMAP::aclCleanup($group->name, 'kolab.org');

                $acl = $imap->getACL('user/john@kolab.org');
                $this->assertTrue(is_array($acl) && !isset($acl[$user->email]));
                $acl = $imap->getACL('shared/Resources/Conference Room #1@kolab.org');
                $this->assertTrue(is_array($acl) && !isset($acl[$user->email]));
        */
    }

    /**
     * Test aclCleanupDomain()
     *
     * @group imap
     */
    public function testAclCleanupDomain(): void
    {
        Queue::fake();

        $ts = str_replace('.', '', (string) microtime(true));
        $this->user = $user = $this->getTestUser("test-{$ts}@kolab.org", [], true);
        $this->group = $group = $this->getTestGroup("test-group-{$ts}@kolab.org");

        // First, set some ACLs that we'll expect to be removed later
        $imap = $this->getImap();

        $this->assertTrue($imap->setACL('user/john@kolab.org', 'anyone', 'lrs'));
        $this->assertTrue($imap->setACL('user/john@kolab.org', 'jack@kolab.org', 'lrs'));
        $this->assertTrue($imap->setACL('user/john@kolab.org', $user->email, 'lrs'));
        $this->assertTrue($imap->setACL('shared/Resources/Conference Room #1@kolab.org', 'anyone', 'lrs'));
        $this->assertTrue($imap->setACL('shared/Resources/Conference Room #1@kolab.org', 'jack@kolab.org', 'lrs'));
        $this->assertTrue($imap->setACL('shared/Resources/Conference Room #1@kolab.org', $user->email, 'lrs'));
        /*
                $this->assertTrue($imap->setACL('user/john@kolab.org', $group->name, 'lrs'));
                $this->assertTrue($imap->setACL('shared/Resources/Conference Room #1@kolab.org', $group->name, 'lrs'));

                $group->delete();
        */
        $user->delete();

        // Cleanup ACL for the domain
        IMAP::aclCleanupDomain('kolab.org');

        $acl = $imap->getACL('user/john@kolab.org');
        $this->assertTrue(is_array($acl));
        $this->assertTrue(!isset($acl[$user->email]));
        $this->assertTrue(isset($acl['jack@kolab.org']));
        $this->assertTrue(isset($acl['anyone']));
        $this->assertTrue(isset($acl['john@kolab.org']));
        // $this->assertTrue(!isset($acl[$group->name]));

        $acl = $imap->getACL('shared/Resources/Conference Room #1@kolab.org');
        $this->assertTrue(is_array($acl));
        $this->assertTrue(!isset($acl[$user->email]));
        $this->assertTrue(isset($acl['jack@kolab.org']));
        $this->assertTrue(isset($acl['anyone']));
        // $this->assertTrue(is_array($acl) && !isset($acl[$group->name]));
    }

    /**
     * Test creating/updating/deleting an IMAP account
     *
     * @group imap
     */
    public function testUsers(): void
    {
        Queue::fake();

        $ts = str_replace('.', '', (string) microtime(true));
        $this->user = $user = $this->getTestUser("test-{$ts}@" . \config('app.domain'), []);
        $storage = Sku::withObjectTenantContext($user)->where('title', 'storage')->first();
        $user->assignSku($storage, 1, $user->wallets->first());

        $expectedQuota = [
            'user/' . $user->email => [
                'storage' => [
                    'used' => 0,
                    'total' => 1048576,
                ],
            ],
        ];

        // Create the mailbox
        $result = IMAP::createUser($user);
        $this->assertTrue($result);
        $this->assertTrue(IMAP::verifyAccount($user->email));
        $this->assertTrue(IMAP::verifyDefaultFolders($user->email));

        $imap = $this->getImap();
        $quota = $imap->getQuota('user/' . $user->email);
        $this->assertSame($expectedQuota, $quota['all']);

        // Create the mailbox again throws exception
        $this->expectException(MailboxExistsException::class);
        IMAP::createUser($user);

        // Update the mailbox (increase quota)
        $user->assignSku($storage, 1, $user->wallets->first());
        $expectedQuota['user/' . $user->email]['storage']['total'] = 1048576 * 2;

        $result = IMAP::updateUser($user);
        $this->assertTrue($result);

        $quota = $imap->getQuota('user/' . $user->email);
        $this->assertSame($expectedQuota, $quota['all']);

        // Delete the mailbox
        $result = IMAP::deleteUser($user);
        $this->assertTrue($result);

        $this->assertFalse(IMAP::verifyAccount($user->email));
    }

    /**
     * Test sharing and unsharing folders (for delegation)
     *
     * @group imap
     */
    public function testShareAndUnshareFolders(): void
    {
        Queue::fake();

        $ts = str_replace('.', '', (string) microtime(true));
        $this->user = $user = $this->getTestUser("test-{$ts}@" . \config('app.domain'), []);
        $this->user2 = $user2 = $this->getTestUser("test2-{$ts}@" . \config('app.domain'), []);

        // Create the mailbox
        $result = IMAP::createUser($user);
        $this->assertTrue($result);

        $imap = $this->getImap();

        // Test delegation without mail folders permissions
        $result = IMAP::shareDefaultFolders($user, $user2, ['event' => 'read-write']);
        $this->assertTrue($result);

        $acl = $imap->getACL("user/{$user->email}");
        $this->assertArrayNotHasKey($user2->email, $acl);

        // Test proper delegation case
        $result = IMAP::shareDefaultFolders($user, $user2, ['mail' => 'read-write']);
        $this->assertTrue($result);

        $acl = $imap->getACL("user/{$user->email}");
        $this->assertSame('lrswitedn', implode('', $acl[$user2->email]));

        foreach (array_keys(\config('services.imap.default_folders')) as $folder) {
            // User folder as seen by cyrus-admin
            $folder = str_replace('@', "/{$folder}@", "user/{$user->email}");
            $acl = $imap->getACL($folder);
            $this->assertSame('lrswitedn', implode('', $acl[$user2->email]));
        }

        $imap = $this->getImap($user2->email);
        $subscribed = $imap->listSubscribed('', "Other Users/*");
        $expected = ["Other Users/test-{$ts}"];
        foreach (array_keys(\config('services.imap.default_folders')) as $folder) {
            $expected[] = "Other Users/test-{$ts}/{$folder}";
        }

        asort($subscribed);
        asort($expected);
        $this->assertSame(array_values($expected), array_values($subscribed));

        // Test unsubscribing these folders
        $result = IMAP::unsubscribeSharedFolders($user2, $user->email);
        $this->assertTrue($result);
        $this->assertSame([], $imap->listSubscribed("Other Users/test-{$ts}", '*'));

        $imap->closeConnection();
        $imap = $this->getImap();

        // Test unsharing these folders
        $result = IMAP::unshareFolders($user, $user2->email);
        $this->assertTrue($result);
        $this->assertNotContains($user2->email, $imap->getACL("user/{$user->email}"));
        foreach (array_keys(\config('services.imap.default_folders')) as $folder) {
            // User folder as seen by cyrus-admin
            $folder = str_replace('@', "/{$folder}@", "user/{$user->email}");
            $this->assertNotContains($user2->email, $imap->getACL($folder));
        }
    }

    /**
     * Test creating/updating/deleting a resource
     *
     * @group imap
     */
    public function testResources(): void
    {
        Queue::fake();

        $ts = str_replace('.', '', (string) microtime(true));
        $this->resource = $resource = $this->getTestResource(
            "test-resource-{$ts}@kolab.org",
            ['name' => "Resource © {$ts}"]
        );

        $resource->setSetting('invitation_policy', 'manual:john@kolab.org');

        // Create the resource
        $this->assertTrue(IMAP::createResource($resource));
        $this->assertTrue(IMAP::verifySharedFolder($imapFolder = $resource->getSetting('folder')));

        $imap = $this->getImap();
        $expectedAcl = ['anyone' => ['p'], 'john@kolab.org' => str_split('lrswipkxtecdn')];
        $acl = $imap->getACL(IMAP::toUTF7($imapFolder));
        $this->assertSame($expectedAcl, $acl);

        // Update the resource (rename)
        $resource->name = "Resource1 © {$ts}";
        $resource->save();
        $newImapFolder = $resource->getSetting('folder');

        $this->assertTrue(IMAP::updateResource($resource, ['folder' => $imapFolder]));
        $this->assertTrue($imapFolder != $newImapFolder);
        $this->assertTrue(IMAP::verifySharedFolder($newImapFolder));
        $acl = $imap->getACL(IMAP::toUTF7($newImapFolder));
        $this->assertSame($expectedAcl, $acl);

        // Update the resource (acl change)
        $resource->setSetting('invitation_policy', 'accept');
        $this->assertTrue(IMAP::updateResource($resource));
        $this->assertSame(['anyone' => ['p']], $imap->getACL(IMAP::toUTF7($newImapFolder)));

        // Delete the resource
        $this->assertTrue(IMAP::deleteResource($resource));
        $this->assertFalse(IMAP::verifySharedFolder($newImapFolder));
    }

    /**
     * Test creating/updating/deleting a shared folder
     *
     * @group imap
     */
    public function testSharedFolders(): void
    {
        Queue::fake();

        $ts = str_replace('.', '', (string) microtime(true));
        $this->folder = $folder = $this->getTestSharedFolder(
            "test-folder-{$ts}@kolab.org",
            ['name' => "SharedFolder © {$ts}"]
        );

        $folder->setSetting('acl', json_encode(['john@kolab.org, full', 'jack@kolab.org, read-only']));

        // Create the shared folder
        $this->assertTrue(IMAP::createSharedFolder($folder));
        $this->assertTrue(IMAP::verifySharedFolder($imapFolder = $folder->getSetting('folder')));

        $imap = $this->getImap();
        $expectedAcl = [
            'anyone' => ['p'],
            'jack@kolab.org' => str_split('lrs'),
            'john@kolab.org' => str_split('lrswipkxtecdn'),
        ];

        $acl = $imap->getACL(IMAP::toUTF7($imapFolder));
        ksort($acl);
        $this->assertSame($expectedAcl, $acl);

        // Update shared folder (acl)
        $folder->setSetting('acl', json_encode(['jack@kolab.org, read-only']));

        $this->assertTrue(IMAP::updateSharedFolder($folder));

        $expectedAcl = [
            'anyone' => ['p'],
            'jack@kolab.org' => str_split('lrs'),
        ];

        $acl = $imap->getACL(IMAP::toUTF7($imapFolder));
        ksort($acl);
        $this->assertSame($expectedAcl, $acl);

        // Update the shared folder (rename)
        $folder->name = "SharedFolder1 © {$ts}";
        $folder->save();
        $newImapFolder = $folder->getSetting('folder');

        $this->assertTrue(IMAP::updateSharedFolder($folder, ['folder' => $imapFolder]));
        $this->assertTrue($imapFolder != $newImapFolder);
        $this->assertTrue(IMAP::verifySharedFolder($newImapFolder));

        $acl = $imap->getACL(IMAP::toUTF7($newImapFolder));
        ksort($acl);
        $this->assertSame($expectedAcl, $acl);

        // Delete the shared folder
        $this->assertTrue(IMAP::deleteSharedFolder($folder));
        $this->assertFalse(IMAP::verifySharedFolder($newImapFolder));
    }

    /**
     * Test verifying IMAP account existence (existing account)
     *
     * @group imap
     */
    public function testVerifyAccountExisting(): void
    {
        // existing user
        $result = IMAP::verifyAccount('john@kolab.org');
        $this->assertTrue($result);

        // non-existing user
        $result = IMAP::verifyAccount('non-existing@domain.tld');
        $this->assertFalse($result);
    }

    /**
     * Test verifying IMAP shared folder existence
     *
     * @group imap
     *
     * The shared/Calendar sometimes verifies and sometimes doesn't.
     * @group skipci
     */
    public function testVerifySharedFolder(): void
    {
        // non-existing
        $result = IMAP::verifySharedFolder('shared/Resources/UnknownResource@kolab.org');
        $this->assertFalse($result);

        // existing
        $result = IMAP::verifySharedFolder('shared/Calendar@kolab.org');
        $this->assertTrue($result);
    }

    /**
     * Test userMailbox
     *
     * @group imap
     */
    public function testUserMailbox(): void
    {
        $this->assertSame(IMAP::userMailbox("john@kolab.org", "INBOX"), "user/john@kolab.org");
        $this->assertSame(IMAP::userMailbox("john@kolab.org", "test"), "user/john/test@kolab.org");
    }

    /**
     * Test clearMailbox
     *
     * @group imap
     */
    public function testClearMailbox(): void
    {
        $imap = $this->getImap("john@kolab.org");
        $message = "From: me@example.com\r\n"
                   . "To: you@example.com\r\n"
                   . "Subject: test\r\n"
                   . "\r\n"
                   . "this is a test message, please ignore\r\n";
        $result = $imap->append("INBOX", $message);
        $this->assertNotFalse($result);

        $result = IMAP::clearMailbox(IMAP::userMailbox("john@kolab.org", "INBOX"));
        $this->assertTrue($result);

        $this->assertSame($imap->countMessages("INBOX"), 0);
    }

    /**
     * Test listMailboxes
     *
     * @group imap
     */
    public function testListMailboxes(): void
    {
        $result = IMAP::listMailboxes("john@kolab.org");
        $this->assertTrue(!empty($result));
    }

    /**
     * Test renameMailbox
     *
     * @group imap
     */
    public function testRenameMailbox(): void
    {
        $imap = $this->getImap("john@kolab.org");
        $imap->createFolder("renametest1");
        $imap->deleteFolder("renametest2");

        $result = IMAP::renameMailbox(
            IMAP::userMailbox("john@kolab.org", "renametest1"),
            IMAP::userMailbox("john@kolab.org", "renametest2")
        );

        $result = IMAP::listMailboxes("john@kolab.org");
        $this->assertTrue(in_array(IMAP::userMailbox("john@kolab.org", "renametest2"), $result));
        $this->assertFalse(in_array(IMAP::userMailbox("john@kolab.org", "renametest1"), $result));
    }

    /**
     * Test copyMailbox
     *
     * @group imap
     */
    public function testCopyMailbox(): void
    {
        $ts = str_replace('.', '', (string) microtime(true));
        $this->user = $user = $this->getTestUser("test-{$ts}@" . \config('app.domain'), [], true);
        $this->user2 = $user2 = $this->getTestUser("test2-{$ts}@" . \config('app.domain'), [], true);

        $imap = $this->getImap($user->email);
        $imap->createFolder("copytest1");

        # TODO add messages

        $result = IMAP::copyMailbox(
            IMAP::userMailbox($user->email, "copytest1"),
            IMAP::userMailbox($user2->email, "copytest2")
        );

        $this->assertTrue($result);

        // Target was created
        $result = IMAP::listMailboxes($user2->email);
        $this->assertTrue(in_array(IMAP::userMailbox($user2->email, "copytest2"), $result));

        // Source is still ok
        $result = IMAP::listMailboxes($user->email);
        $this->assertTrue(in_array(IMAP::userMailbox($user->email, "copytest1"), $result));

        # TODO verify messages
    }

    /**
     * Get configured/initialized rcube_imap_generic instance
     */
    private function getImap($loginAs = null)
    {
        if ($this->imap && !$loginAs) {
            return $this->imap;
        }

        $class = new \ReflectionClass(IMAP::class);
        $init = $class->getMethod('initIMAP');
        $config = $class->getMethod('getConfig');
        $init->setAccessible(true);
        $config->setAccessible(true);

        $config = $config->invoke(null);

        if ($loginAs) {
            return $init->invokeArgs(null, [$config, $loginAs]);
        }

        return $this->imap = $init->invokeArgs(null, [$config]);
    }
}
