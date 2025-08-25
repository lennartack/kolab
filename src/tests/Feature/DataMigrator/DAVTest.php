<?php

namespace Tests\Feature\DataMigrator;

use App\Backends\DAV\Vevent;
use App\DataMigrator\Account;
use App\DataMigrator\Driver\DAV;
use App\DataMigrator\Engine;
use App\DataMigrator\Interface\Folder;
use App\DataMigrator\Interface\Item;
use App\DataMigrator\Queue as MigratorQueue;
use Illuminate\Support\Facades\Http;
use Tests\BackendsTrait;
use Tests\TestCase;

/**
 * @group slow
 */
class DAVTest extends TestCase
{
    use BackendsTrait;

    protected function setUp(): void
    {
        parent::setUp();

        MigratorQueue::truncate();
    }

    protected function tearDown(): void
    {
        MigratorQueue::truncate();

        parent::tearDown();
    }

    /**
     * Test DAV to DAV migration
     *
     * @group dav
     */
    public function testInitialMigration(): void
    {
        $uri = \config('services.dav.uri');

        $uri = preg_replace('|^http|', 'dav', $uri);
        $src = new Account(preg_replace('|://|', '://john%40kolab.org:simple123@', $uri));
        $dst = new Account(preg_replace('|://|', '://jack%40kolab.org:simple123@', $uri));

        // Initialize accounts
        $this->initAccount($src);
        $this->initAccount($dst);

        // Add some items to the source account
        $this->davAppend($src, 'Calendar', ['event/1.ics', 'event/2.ics'], Engine::TYPE_EVENT);
        $this->davCreateFolder($src, 'DavDataMigrator', Engine::TYPE_CONTACT);
        $this->davCreateFolder($src, 'DavDataMigrator/Test', Engine::TYPE_CONTACT);
        $this->davAppend($src, 'DavDataMigrator/Test', ['contact/1.vcf', 'contact/2.vcf'], Engine::TYPE_CONTACT);

        // Clean up the destination folders structure
        $this->davDeleteFolder($dst, 'DavDataMigrator', Engine::TYPE_CONTACT);
        $this->davDeleteFolder($dst, 'DavDataMigrator/Test', Engine::TYPE_CONTACT);

        // Run the migration
        $migrator = new Engine();
        $migrator->migrate($src, $dst, ['force' => true, 'sync' => true, 'type' => 'event,contact']);

        // Assert the destination account
        $dstFolders = $this->davListFolders($dst, Engine::TYPE_CONTACT);
        $this->assertContains('DavDataMigrator', $dstFolders);
        $this->assertContains('DavDataMigrator/Test', $dstFolders);

        // Assert the migrated events
        $dstObjects = $this->davList($dst, 'Calendar', Engine::TYPE_EVENT);
        $events = \collect($dstObjects)->keyBy('uid')->all();
        $this->assertCount(2, $events);
        $this->assertSame('Party', $events['abcdef']->summary);
        $this->assertSame('Meeting', $events['123456']->summary);

        // Assert the migrated contacts and contact folders
        $dstObjects = $this->davList($dst, 'DavDataMigrator/Test', Engine::TYPE_CONTACT);
        $contacts = \collect($dstObjects)->keyBy('uid')->all();
        $this->assertCount(2, $contacts);
        $this->assertSame('Jane Doe', $contacts['uid1']->fn);
        $this->assertSame('Jack Strong', $contacts['uid2']->fn);
    }

    /**
     * Test DAV to DAV incremental migration run
     *
     * @group dav
     *
     * @depends testInitialMigration
     */
    public function testIncrementalMigration(): void
    {
        $uri = \config('services.dav.uri');

        $uri = preg_replace('|^http|', 'dav', $uri);
        $src = new Account(preg_replace('|://|', '://john%40kolab.org:simple123@', $uri));
        $dst = new Account(preg_replace('|://|', '://jack%40kolab.org:simple123@', $uri));

        // Add an event and modify another one
        $srcEvents = $this->davList($src, 'Calendar', Engine::TYPE_EVENT);
        $this->davAppend($src, 'Calendar', ['event/3.ics', 'event/1.1.ics'], Engine::TYPE_EVENT);

        // Run the migration
        $migrator = new Engine();
        $migrator->migrate($src, $dst, ['force' => true, 'sync' => true, 'type' => Engine::TYPE_EVENT]);

        // Assert the migrated events
        $dstObjects = $this->davList($dst, 'Calendar', Engine::TYPE_EVENT);
        $events = \collect($dstObjects)->keyBy('uid')->all();
        $this->assertCount(3, $events);
        $this->assertSame('Party Update', $events['abcdef']->summary);
        $this->assertSame('Meeting', $events['123456']->summary);
        $this->assertSame('Test Summary', $events['aaa-aaa']->summary);

        // TODO: Assert that unmodified objects aren't migrated again
    }

    /**
     * Test fixing data in DAV migration
     */
    public function testDataRepair(): void
    {
        $uri = \config('services.dav.uri');
        $uri = preg_replace('|^http|', 'dav', $uri);
        $src = new Account(preg_replace('|://|', '://john%40kolab.org:simple123@', $uri));

        // Create a fake object to fetch from a fake DAV server
        $folder = Folder::fromArray([
            'fullname' => 'Calendar',
            'id' => '/test/0',
            'type' => Engine::TYPE_EVENT,
        ]);

        $item = Item::fromArray([
            'id' => '/test/1.ics',
            'folder' => $folder,
        ]);

        $engine = new Engine();

        $report = file_get_contents(self::BASE_DIR . '/data/DAV/report1.xml');
        Http::fake([
            'dav/test' => Http::response($report, 207, ['Content-Type' => 'application/xml; charset=utf-8']),
        ]);

        // Fetch the item from a fake DAV server
        // Note: For simplicity we do not attempt to store the event into a DAV server
        $driver = new DAV($src, $engine);
        $driver->fetchItem($item);

        $event = new Vevent();
        $this->invokeMethod($event, 'fromIcal', [$item->content]);
        $this->assertSame('jack@kolab.org', $event->organizer['email']);
        $this->assertSame('jack@kolab.org', $event->exceptions[0]->organizer['email']);
        $this->assertSame('john@kolab.org', $event->exceptions[0]->attendees[0]['email']);
        $this->assertSame('CHAIR', $event->exceptions[0]->attendees[0]['role']);

        // Note: More data repair tests should be placed in another location
    }
}
