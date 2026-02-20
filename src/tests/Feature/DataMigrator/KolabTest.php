<?php

namespace Tests\Feature\DataMigrator;

use App\DataMigrator\Account;
use App\DataMigrator\Driver\Kolab;
use App\DataMigrator\Driver\Kolab\Tags as KolabTags;
use App\DataMigrator\Engine;
use App\DataMigrator\Interface\Folder;
use App\DataMigrator\Queue as MigratorQueue;
use App\Fs\Item as FsItem;
use App\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Tests\BackendsTrait;
use Tests\TestCase;

/**
 * @group slow
 * @group dav
 * @group imap
 * @group files
 */
class KolabTest extends TestCase
{
    use BackendsTrait;

    private static $skipTearDown = false;
    private static $skipSetUp = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$skipSetUp) {
            MigratorQueue::truncate();
            FsItem::query()->forceDelete();
        }

        self::$skipSetUp = false;
    }

    protected function tearDown(): void
    {
        if (!self::$skipTearDown) {
            MigratorQueue::truncate();
            FsItem::query()->forceDelete();

            $disk = LaravelStorage::disk(\config('filesystems.default'));
            foreach ($disk->listContents('') as $dir) {
                $disk->deleteDirectory($dir->path());
            }
        }

        self::$skipTearDown = false;

        parent::tearDown();
    }

    /**
     * Test Kolab3 to Kolab4 migration
     */
    public function testInitialMigration3to4(): void
    {
        [$src, $src_imap, $src_dav, $dst, $dst_imap, $dst_dav] = $this->getTestAccounts();

        $this->prepareKolab3Account($src_imap, $src_dav);
        $this->prepareKolab4Account($dst_imap, $dst_dav);

        // Run the migration
        $migrator = new Engine();
        $migrator->migrate($src, $dst, ['force' => true, 'sync' => true]);

        $imap = $this->getImapClient($dst_imap);
        $dav = $this->getDavClient($dst_dav);

        // Assert the migrated mail
        $messages = $this->imapList($dst_imap, 'INBOX');
        $messages = \collect($messages)->keyBy('messageID')->all();
        $this->assertCount(2, $messages);
        $this->assertSame([], $messages['<sync1@kolab.org>']->flags);
        $this->assertSame(['SEEN'], array_keys($messages['<sync2@kolab.org>']->flags));
        $sync1_uid = $messages['<sync1@kolab.org>']->uid;
        $sync2_uid = $messages['<sync2@kolab.org>']->uid;

        $messages = $this->imapList($dst_imap, 'Drafts');
        $messages = \collect($messages)->keyBy('messageID')->all();
        $this->assertCount(1, $messages);
        $this->assertSame(['SEEN'], array_keys($messages['<sync3@kolab.org>']->flags));

        $this->assertCount(0, $this->imapList($dst_imap, 'Spam'));
        $this->assertCount(0, $this->imapList($dst_imap, 'Trash'));

        // Assert non-mail folders were not migrated
        $mail_folders = $this->imapListFolders($dst_imap);
        $this->assertNotContains('Configuration', $mail_folders);
        $this->assertNotContains('Test', $mail_folders);

        // Extra IMAP folder with non-ascii characters
        $utf7_folder = \mb_convert_encoding('&kość', 'UTF7-IMAP', 'UTF8');
        $this->assertContains($utf7_folder, $mail_folders);

        // Check migration of folder subscription state and ACL
        $subscribed = $this->imapListFolders($dst_imap, true);
        $this->assertNotContains($utf7_folder, $subscribed);
        $this->assertContains('Test2', $subscribed);
        $this->assertSame('lrswi', implode('', $imap->getACL('Test2')['john@kolab.org']));

        // Assert migrated tags
        $tags = KolabTags::getKolab4Tags($imap);
        $this->assertCount(1, $tags);
        $this->assertSame('tag', $tags[0]['name']);
        $this->assertSame('#E0431B', $tags[0]['color']);
        $members = KolabTags::getKolab4TagMembers($imap, 'tag', 'INBOX');
        $this->assertSame([(string) $sync1_uid, (string) $sync2_uid], $members);

        // Assert the migrated events
        $events = $this->davList($dst_dav, 'Calendar', Engine::TYPE_EVENT);
        $events = \collect($events)->keyBy('uid')->all();
        $this->assertCount(2, $events);
        $this->assertSame('Party', $events['abcdef']->summary);
        $this->assertSame('Meeting', $events['123456']->summary);

        $events = $this->davList($dst_dav, 'Calendar/Custom Calendar', Engine::TYPE_EVENT);
        $events = \collect($events)->keyBy('uid')->all();
        $this->assertCount(1, $events);
        $this->assertSame('Test Summary', $events['aaa-aaa']->summary);

        // Assert the migrated contacts
        $contacts = $this->davList($dst_dav, 'Contacts', Engine::TYPE_CONTACT);
        $contacts = \collect($contacts)->keyBy('uid')->all();
        $this->assertCount(2, $contacts);
        $this->assertSame('Jane Doe', $contacts['uid1']->fn);
        $this->assertSame('Jack Strong', $contacts['uid2']->fn);

        // Assert the migrated tasks
        $tasks = $this->davList($dst_dav, 'Tasks', Engine::TYPE_TASK);
        $tasks = \collect($tasks)->keyBy('uid')->all();
        $this->assertCount(2, $tasks);
        $this->assertSame('Task1', $tasks['ccc-ccc']->summary);
        $this->assertSame('Task2', $tasks['ddd-ddd']->summary);

        // Assert migrated ACL/sharees on a DAV folder
        $folder = $dav->folderInfo("/calendars/user/{$dst->email}/Default"); // 'Calendar' folder
        $this->assertCount(1, $folder->invites);
        $this->assertSame('read-write', $folder->invites['mailto:john@kolab.org']['access']);

        // DAV folder properties (color)
        $this->assertSame('AABB' . sprintf('%02d', date('d')), $folder->color);

        // Assert migrated files
        $user = $dst_imap->getUser();
        $folders = $user->fsItems()->where('type', '&', FsItem::TYPE_COLLECTION)
            ->select('fs_items.*')
            ->addSelect(DB::raw("(select value from fs_properties where fs_properties.item_id = fs_items.id"
                . " and fs_properties.key = 'name') as name"))
            ->get()
            ->keyBy('name')
            ->all();
        $files = $user->fsItems()->whereNot('type', '&', FsItem::TYPE_COLLECTION)
            ->select('fs_items.*')
            ->addSelect(DB::raw("(select value from fs_properties where fs_properties.item_id = fs_items.id"
                . " and fs_properties.key = 'name') as name"))
            ->get()
            ->keyBy('name')
            ->all();

        $this->assertSame(4, count($folders));
        $this->assertSame(6, count($files));
        $this->assertArrayHasKey('A€B', $folders);
        $this->assertArrayHasKey('Files', $folders);
        $this->assertTrue($folders['Files']->children->contains($folders['A€B']));
        $this->assertTrue($folders['Files']->children->contains($files['empty.txt']));
        $this->assertTrue($folders['Files']->children->contains($files['&kość.odt']));
        $this->assertTrue($folders['A€B']->children->contains($files['test2.odt']));
        $this->assertSame(10000, (int) $files['&kość.odt']->getProperty('size'));
        $this->assertSame(0, (int) $files['empty.txt']->getProperty('size'));
        $this->assertSame(10000, (int) $files['test2.odt']->getProperty('size'));
        $this->assertSame('application/vnd.oasis.opendocument.odt', $files['&kość.odt']->getProperty('mimetype'));
        $this->assertSame('text/plain', $files['empty.txt']->getProperty('mimetype'));
        $this->assertSame('application/vnd.oasis.opendocument.odt', $files['test2.odt']->getProperty('mimetype'));
        $this->assertSame('2024-01-10 09:09:09', $files['&kość.odt']->updated_at->toDateTimeString());
        $file_content = str_repeat('1234567890', 1000);
        $this->assertSame($file_content, Storage::fileFetch($files['&kość.odt']));
        $this->assertSame($file_content, Storage::fileFetch($files['test2.odt']));
        $this->assertSame('', Storage::fileFetch($files['empty.txt']));

        // Assert migrated notes
        $this->assertArrayHasKey('Notes', $folders);
        $this->assertArrayHasKey('Notes » Sub Notes', $folders);
        $this->assertSame(0, $folders['Notes']->parents()->count());
        $this->assertSame(0, $folders['Notes » Sub Notes']->parents()->count());
        $this->assertTrue($folders['Notes']->isNotebook());
        $this->assertTrue($folders['Notes » Sub Notes']->isNotebook());
        $this->assertTrue($folders['Notes']->children->contains($files['111-111.html']));
        $this->assertTrue($folders['Notes']->children->contains($files['222-222.html']));
        $this->assertTrue($folders['Notes » Sub Notes']->children->contains($files['333-333.html']));
        $this->assertSame('Note summary 1', $files['111-111.html']->getProperty('dav:displayname'));
        $this->assertSame('Note summary 2', $files['222-222.html']->getProperty('dav:displayname'));
        $this->assertSame('Note summary 3', $files['333-333.html']->getProperty('dav:displayname'));
        $this->assertSame('text/html', $files['111-111.html']->getProperty('mimetype'));
        $this->assertSame('<html><pre>plain text description</pre></html>', Storage::fileFetch($files['111-111.html']));
        $this->assertSame('<html><body>html description X</body></html>', Storage::fileFetch($files['333-333.html']));
        $this->assertSame('["tag"]', $files['111-111.html']->getProperty('dav:categories'));
        $this->assertNull($files['222-222.html']->getProperty('dav:categories'));
        $links = json_decode($files['111-111.html']->getProperty('dav:links'), true);
        $this->assertCount(1, $links);
        $this->assertStringStartsWith('imap:///user/ned%40kolab.org/INBOX', $links[0]);

        self::$skipTearDown = true;
        self::$skipSetUp = true;
    }

    /**
     * Test Kolab3 to Kolab4 incremental migration run
     *
     * @depends testInitialMigration3to4
     */
    public function testIncrementalMigration3to4(): void
    {
        [$src, $src_imap, $src_dav, $dst, $dst_imap, $dst_dav] = $this->getTestAccounts();

        // Add/modify some source account data
        $messages = $this->imapList($src_imap, 'INBOX');
        $existing = \collect($messages)->keyBy('messageID')->all();
        $this->imapFlagAs($src_imap, 'INBOX', $existing['<sync1@kolab.org>']->uid, ['SEEN', 'FLAGGED']);
        $this->imapFlagAs($src_imap, 'INBOX', $existing['<sync2@kolab.org>']->uid, ['UNSEEN']);
        $this->imapAppend($src_imap, 'Configuration', 'kolab3/tag2.eml');
        $this->imapAppend($src_imap, 'Drafts', 'mail/4.eml', ['SEEN']);
        $replace = [
            // '/john@kolab.org/' => 'ned@kolab.org',
            '/DTSTAMP:19970714T170000Z/' => 'DTSTAMP:20240714T170000Z',
            '/SUMMARY:Party/' => 'SUMMARY:Test',
        ];
        $this->davAppend($src_dav, 'Calendar', ['event/1.ics'], Engine::TYPE_EVENT, $replace);
        $this->imapEmptyFolder($src_imap, 'Files');
        $file_content = rtrim(chunk_split(base64_encode('123'), 76, "\r\n"));
        $replaces = ['/%FILE%/' => $file_content];
        $this->imapAppend($src_imap, 'Files', 'kolab3/file1.eml', [], '12-Jan-2024 09:09:09 +0000', $replaces);
        $this->imapEmptyFolder($src_imap, 'Notes');
        $replaces = [
            '/<summary>.*<\/summary>/' => '<summary>mod</summary>',
            '/<description>.*<\/description>/' => '<description>mod text</description>',
        ];
        $this->imapAppend($src_imap, 'Notes', 'kolab3/note1.eml', [], '12-Jan-2024 09:09:20 +0000', $replaces);
        $this->imapAppend($src_imap, 'Notes', 'kolab3/note2.eml');

        // Run the migration
        $migrator = new Engine();
        $migrator->migrate($src, $dst, ['force' => true, 'sync' => true]);

        // Assert the migrated mail
        $messages = $this->imapList($dst_imap, 'INBOX');
        $messages = \collect($messages)->keyBy('messageID')->all();
        $this->assertCount(2, $messages);
        $this->assertSame(['FLAGGED', 'SEEN'], array_keys($messages['<sync1@kolab.org>']->flags));
        $this->assertSame([], $messages['<sync2@kolab.org>']->flags);
        $sync2_uid = $messages['<sync2@kolab.org>']->uid;

        $messages = $this->imapList($dst_imap, 'Drafts');
        $messages = \collect($messages)->keyBy('messageID')->all();
        $this->assertCount(2, $messages);
        $this->assertSame(['SEEN'], array_keys($messages['<sync3@kolab.org>']->flags));
        $this->assertSame(['SEEN'], array_keys($messages['<sync4@kolab.org>']->flags));
        $sync3_uid = $messages['<sync3@kolab.org>']->uid;
        $sync4_uid = $messages['<sync4@kolab.org>']->uid;

        // Assert migrated tags
        $imap = $this->getImapClient($dst_imap);
        $tags = KolabTags::getKolab4Tags($imap);
        $this->assertCount(2, $tags);
        $this->assertSame('tag', $tags[0]['name']);
        $this->assertSame('#E0431B', $tags[0]['color']);
        $this->assertSame('test', $tags[1]['name']);
        $this->assertSame('#FFFFFF', $tags[1]['color']);
        $members = KolabTags::getKolab4TagMembers($imap, 'test', 'INBOX');
        $this->assertSame([(string) $sync2_uid], $members);
        $members = KolabTags::getKolab4TagMembers($imap, 'test', 'Drafts');
        $this->assertSame([(string) $sync3_uid, (string) $sync4_uid], $members);

        // Assert the migrated events
        $events = $this->davList($dst_dav, 'Calendar', Engine::TYPE_EVENT);
        $events = \collect($events)->keyBy('uid')->all();
        $this->assertCount(2, $events);
        $this->assertSame('Test', $events['abcdef']->summary);

        // Assert the migrated files
        $user = $dst_imap->getUser();
        $files = $user->fsItems()->whereNot('type', '&', FsItem::TYPE_COLLECTION)
            ->select('fs_items.*')
            ->addSelect(DB::raw("(select value from fs_properties where fs_properties.item_id = fs_items.id"
                . " and fs_properties.key = 'name') as name"))
            ->get()
            ->keyBy('name')
            ->all();
        $this->assertSame(6, count($files));
        $this->assertSame(3, (int) $files['&kość.odt']->getProperty('size'));
        $this->assertSame('application/vnd.oasis.opendocument.odt', $files['&kość.odt']->getProperty('mimetype'));
        $this->assertSame('2024-01-12 09:09:09', $files['&kość.odt']->updated_at->toDateTimeString());
        $this->assertSame('123', Storage::fileFetch($files['&kość.odt']));

        // Assert notes
        $this->assertSame('mod', $files['111-111.html']->getProperty('dav:displayname'));
        $this->assertSame('<html><pre>mod text</pre></html>', Storage::fileFetch($files['111-111.html']));
        $this->assertSame('["tag","test"]', $files['111-111.html']->getProperty('dav:categories'));
        $this->assertSame('["test"]', $files['222-222.html']->getProperty('dav:categories'));
    }

    /**
     * Test user impersonation support
     */
    public function testUserImpersonation(): void
    {
        [$src, $src_imap, $src_dav, $dst, $dst_imap, $dst_dav] = $this->getTestAccounts();

        $this->initAccount($dst_imap);
        $this->initAccount($dst_dav);
        $this->imapAppend($dst_imap, 'INBOX', 'mail/1.eml');
        $this->davAppend($dst_dav, 'Calendar', ['event/3.ics'], Engine::TYPE_EVENT);

        $adminUser = \config('services.imap.admin_login');
        $adminPass = \config('services.imap.admin_password');
        $dst_impersonated = str_replace('jack%40kolab.org:simple123', "{$adminUser}:{$adminPass}", (string) $dst)
            . '&user=jack@kolab.org';

        $migrator = new Engine();
        $account = new Account($dst_impersonated);
        $driver = new Kolab($account, $migrator);

        // This involves IMAP and DAV authentication, so it should be enough,
        // but we make some more assertions to make sure we're connected to the proper account.
        $driver->authenticate();

        $mail_items = $driver->getItems(Folder::fromArray(['fullname' => 'INBOX', 'type' => Engine::TYPE_MAIL]));
        $this->assertCount(1, $mail_items);
        $this->assertArrayHasKey('<sync1@kolab.org>', $mail_items);
        $event_items = $driver->getItems(Folder::fromArray(['fullname' => 'Calendar', 'type' => Engine::TYPE_EVENT]));
        $this->assertCount(1, $event_items);
        $this->assertArrayHasKey('aaa-aaa', $event_items);

        // TODO: We'll need a Kolab3 installation (iRony) to test if impersonation works there
    }

    /**
     * Initialize accounts for tests
     */
    private function getTestAccounts()
    {
        $dav_uri = \config('services.dav.uri');
        $dav_uri = preg_replace('|^http|', 'dav', $dav_uri);
        $imap_uri = \config('services.imap.uri');
        if (!str_contains($imap_uri, '://')) {
            $imap_uri = 'imap://' . $imap_uri;
        }

        $kolab3_uri = preg_replace('|^[a-z]+://|', 'kolab3://ned%40kolab.org:simple123@', $imap_uri)
            . '?dav_host=' . preg_replace('|^davs?://|', '', $dav_uri)
            . '&v4dav=true';
        $kolab4_uri = preg_replace('|^[a-z]+://|', 'kolab4://jack%40kolab.org:simple123@', $imap_uri)
            . '?dav_host=' . preg_replace('|^davs?://|', '', $dav_uri);

        // Note: These are Kolab4 accounts, we'll modify the src account to imitate a Kolab3 account
        // as much as we can. See self::prepareKolab3Account()

        $src_imap = new Account(preg_replace('|://|', '://ned%40kolab.org:simple123@', $imap_uri));
        $src_dav = new Account(preg_replace('|://|', '://ned%40kolab.org:simple123@', $dav_uri));
        $src = new Account($kolab3_uri);
        $dst_imap = new Account(preg_replace('|://|', '://jack%40kolab.org:simple123@', $imap_uri));
        $dst_dav = new Account(preg_replace('|://|', '://jack%40kolab.org:simple123@', $dav_uri));
        $dst = new Account($kolab4_uri);

        return [$src, $src_imap, $src_dav, $dst, $dst_imap, $dst_dav];
    }

    /**
     * Initial preparation of a Kolab v3 account for tests
     */
    private function prepareKolab3Account(Account $imap_account, Account $dav_account)
    {
        // Cleanup the account
        $this->initAccount($imap_account);
        $this->initAccount($dav_account);

        $imap = $this->getImapClient($imap_account);

        // Create Configuration folder with sample relation (tag) object
        $this->imapCreateFolder($imap_account, 'Configuration');
        $this->imapEmptyFolder($imap_account, 'Configuration');
        if (!$imap->setMetadata('Configuration', ['/private/vendor/kolab/folder-type' => 'configuration'])) {
            throw new \Exception("Failed to set metadata");
        }
        $this->imapAppend($imap_account, 'Configuration', 'kolab3/tag1.eml');
        $this->imapAppend($imap_account, 'Configuration', 'kolab3/tag2.eml', ['DELETED']);
        $this->imapAppend($imap_account, 'Configuration', 'kolab3/relation1.eml');

        // Create a non-mail folder, we'll assert that it was skipped in migration
        $this->imapCreateFolder($imap_account, 'Test');
        if (!$imap->setMetadata('Test', ['/private/vendor/kolab/folder-type' => 'journal'])) {
            throw new \Exception("Failed to set metadata");
        }
        if (!$imap->setMetadata('INBOX', ['/private/vendor/kolab/folder-type' => 'mail.inbox'])) {
            throw new \Exception("Failed to set metadata");
        }

        // Create an IMAP folder with non-ascii characters, unsubscribed
        $this->imapCreateFolder($imap_account, \mb_convert_encoding('&kość', 'UTF7-IMAP', 'UTF8'));

        // One more IMAP folder, subscribed
        $this->imapCreateFolder($imap_account, 'Test2', true);
        $imap->setACL('Test2', 'john@kolab.org', 'lrswi');

        // Calendar, Contact, Tasks, Files folders
        $utf7_folder = \mb_convert_encoding('Files/A€B', 'UTF7-IMAP', 'UTF8');
        $folders = [
            'Calendar' => 'event.default',
            'Calendar/Custom Calendar' => 'event',
            'Tasks' => 'task',
            'Contacts' => 'contact.default',
            'Files' => 'file.default',
            'Notes' => 'note.default',
            'Notes/Sub Notes' => 'note',
        ];
        $folders[$utf7_folder] = 'file';
        foreach ($folders as $name => $type) {
            $this->imapCreateFolder($imap_account, $name);
            $this->imapEmptyFolder($imap_account, $name);
            if (!$imap->setMetadata($name, ['/private/vendor/kolab/folder-type' => $type])) {
                throw new \Exception("Failed to set metadata");
            }
        }
        $imap->setACL('Calendar', 'john@kolab.org', 'lrswi');
        if (!$imap->setMetadata('Calendar', ['/shared/vendor/kolab/color' => 'AABB' . sprintf('%02d', date('d'))])) {
            throw new \Exception("Failed to set metadata");
        }

        // Insert some files
        $file_content = str_repeat('1234567890', 1000);
        $file_content = rtrim(chunk_split(base64_encode($file_content), 76, "\r\n"));
        $replaces = ['/%FILE%/' => $file_content];
        $this->imapAppend($imap_account, 'Files', 'kolab3/file1.eml', [], '10-Jan-2024 09:09:09 +0000', $replaces);
        $this->imapAppend($imap_account, 'Files', 'kolab3/file2.eml', [], '11-Jan-2024 09:09:09 +0000');
        $replaces['/&amp;kość.odt/'] = 'test2.odt';
        $replaces['/&ko%C5%9B%C4%87.odt/'] = 'test2.odt';
        $this->imapAppend($imap_account, $utf7_folder, 'kolab3/file1.eml', [], '12-Jan-2024 09:09:09 +0000', $replaces);

        // Insert some notes
        $this->imapEmptyFolder($imap_account, 'Notes');
        $this->imapEmptyFolder($imap_account, 'Notes/Sub Notes');
        $this->imapAppend($imap_account, 'Notes', 'kolab3/note1.eml', [], '12-Jan-2024 09:09:09 +0000');
        $this->imapAppend($imap_account, 'Notes', 'kolab3/note2.eml', [], '12-Jan-2024 09:09:10 +0000');
        $this->imapAppend($imap_account, 'Notes/Sub Notes', 'kolab3/note3.eml', [], '12-Jan-2024 09:09:11 +0000');

        // Insert some mail to migrate
        $this->imapEmptyFolder($imap_account, 'INBOX');
        $this->imapEmptyFolder($imap_account, 'Drafts');
        $this->imapAppend($imap_account, 'INBOX', 'mail/1.eml');
        $this->imapAppend($imap_account, 'INBOX', 'mail/2.eml', ['SEEN']);
        $this->imapAppend($imap_account, 'Drafts', 'mail/3.eml', ['SEEN']);

        // Insert some DAV data to migrate
        $this->davCreateFolder($dav_account, 'Calendar/Custom Calendar', Engine::TYPE_EVENT);
        $this->davEmptyFolder($dav_account, 'Calendar', Engine::TYPE_EVENT);
        $this->davEmptyFolder($dav_account, 'Calendar/Custom Calendar', Engine::TYPE_EVENT);
        $this->davEmptyFolder($dav_account, 'Contacts', Engine::TYPE_CONTACT);
        $this->davEmptyFolder($dav_account, 'Tasks', Engine::TYPE_TASK);
        $this->davAppend($dav_account, 'Calendar', ['event/1.ics', 'event/2.ics'], Engine::TYPE_EVENT);
        $this->davAppend($dav_account, 'Calendar/Custom Calendar', ['event/3.ics'], Engine::TYPE_EVENT);
        $this->davAppend($dav_account, 'Contacts', ['contact/1.vcf', 'contact/2.vcf'], Engine::TYPE_CONTACT);
        $this->davAppend($dav_account, 'Tasks', ['task/1.ics', 'task/2.ics'], Engine::TYPE_TASK);
    }

    /**
     * Initial preparation of a Kolab v4 account for tests
     */
    private function prepareKolab4Account(Account $imap_account, Account $dav_account)
    {
        $this->initAccount($imap_account);
        $this->initAccount($dav_account);
        $this->davDeleteFolder($dav_account, 'Calendar/Custom Calendar', Engine::TYPE_EVENT);
        $this->imapDeleteFolder($imap_account, 'Test');
        $this->imapDeleteFolder($imap_account, 'Test2');
        $this->imapDeleteFolder($imap_account, \mb_convert_encoding('&kość', 'UTF7-IMAP', 'UTF8'));
        $this->imapDeleteFolder($imap_account, 'Configuration');
        $imap = $this->getImapClient($imap_account);
        KolabTags::saveKolab4Tags($imap, []);
    }
}
