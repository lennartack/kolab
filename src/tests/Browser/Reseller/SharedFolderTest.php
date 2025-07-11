<?php

namespace Tests\Browser\Reseller;

use App\SharedFolder;
use App\Utils;
use Illuminate\Support\Facades\Queue;
use Tests\Browser;
use Tests\Browser\Pages\Admin\SharedFolder as SharedFolderPage;
use Tests\Browser\Pages\Admin\User as UserPage;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\Home;
use Tests\TestCaseDusk;

class SharedFolderTest extends TestCaseDusk
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useResellerUrl();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Test shared folder info page (unauthenticated)
     */
    public function testSharedFolderUnauth(): void
    {
        // Test that the page requires authentication
        $this->browse(function (Browser $browser) {
            $user = $this->getTestUser('john@kolab.org');
            $folder = $this->getTestSharedFolder('folder-mail@kolab.org');

            $browser->visit('/shared-folder/' . $folder->id)->on(new Home());
        });
    }

    /**
     * Test shared folder info page
     */
    public function testInfo(): void
    {
        Queue::fake();

        $this->browse(function (Browser $browser) {
            $user = $this->getTestUser('john@kolab.org');
            $folder = $this->getTestSharedFolder('folder-mail@kolab.org');
            $folder->setConfig(['acl' => ['anyone, read-only', 'jack@kolab.org, read-write']]);
            $folder->setAliases(['folder-alias1@kolab.org', 'folder-alias2@kolab.org']);
            $folder->status = SharedFolder::STATUS_NEW | SharedFolder::STATUS_ACTIVE
                | SharedFolder::STATUS_LDAP_READY | SharedFolder::STATUS_IMAP_READY;
            $folder->save();

            $folder_page = new SharedFolderPage($folder->id);
            $user_page = new UserPage($user->id);
            $count = in_array('event', config('app.shared_folder_types')) ? 3 : 1;

            // Goto the folder page
            $browser->visit(new Home())
                ->submitLogon('reseller@' . \config('app.domain'), Utils::generatePassphrase(), true)
                ->on(new Dashboard())
                ->visit($user_page)
                ->on($user_page)
                ->click('@nav #tab-folders')
                ->pause(1000)
                ->click("@user-folders table tbody tr:nth-child({$count}) td:first-child a")
                ->on($folder_page)
                ->assertSeeIn('@folder-info .card-title', $folder->email)
                ->with('@folder-info form', static function (Browser $browser) use ($folder) {
                    $browser->assertElementsCount('.row', 4)
                        ->assertSeeIn('.row:nth-child(1) label', 'ID (Created)')
                        ->assertSeeIn('.row:nth-child(1) #folderid', "{$folder->id} ({$folder->created_at})")
                        ->assertSeeIn('.row:nth-child(2) label', 'Status')
                        ->assertSeeIn('.row:nth-child(2) #status.text-success', 'Active')
                        ->assertSeeIn('.row:nth-child(3) label', 'Name')
                        ->assertSeeIn('.row:nth-child(3) #name', $folder->name)
                        ->assertSeeIn('.row:nth-child(4) label', 'Type')
                        ->assertSeeIn('.row:nth-child(4) #type', 'Mail');
                })
                ->assertElementsCount('ul.nav-tabs .nav-item', 2)
                ->assertSeeIn('ul.nav-tabs .nav-item:nth-child(1) .nav-link', 'Settings')
                ->with('@folder-settings form', static function (Browser $browser) {
                    $browser->assertElementsCount('.row', 1)
                        ->assertSeeIn('.row:nth-child(1) label', 'Access rights')
                        ->assertSeeIn('.row:nth-child(1) #acl', 'anyone: read-only')
                        ->assertSeeIn('.row:nth-child(1) #acl', 'jack@kolab.org: read-write');
                })
                ->assertSeeIn('ul.nav-tabs .nav-item:nth-child(2) .nav-link', 'Email Aliases (2)')
                ->click('ul.nav-tabs .nav-item:nth-child(2) .nav-link')
                ->with('@folder-aliases table', static function (Browser $browser) {
                    $browser->assertElementsCount('tbody tr', 2)
                        ->assertSeeIn('tbody tr:nth-child(1) td', 'folder-alias1@kolab.org')
                        ->assertSeeIn('tbody tr:nth-child(2) td', 'folder-alias2@kolab.org');
                });

            // Test invalid shared folder identifier
            $browser->visit('/shared-folder/abc')->assertErrorPage(404);
        });
    }
}
