<?php

namespace Tests\Browser;

use App\SharedFolder;
use Tests\Browser;
use Tests\Browser\Components\AclInput;
use Tests\Browser\Components\ListInput;
use Tests\Browser\Components\Status;
use Tests\Browser\Components\Toast;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\Home;
use Tests\Browser\Pages\SharedFolderInfo;
use Tests\Browser\Pages\SharedFolderList;
use Tests\TestCaseDusk;

class SharedFolderTest extends TestCaseDusk
{
    protected function setUp(): void
    {
        parent::setUp();

        SharedFolder::whereNotIn('name', ['Contacts', 'Calendar', 'Library'])->delete();
    }

    protected function tearDown(): void
    {
        SharedFolder::whereNotIn('name', ['Contacts', 'Calendar', 'Library'])->delete();

        parent::tearDown();
    }

    /**
     * Test shared folder info page (unauthenticated)
     */
    public function testInfoUnauth(): void
    {
        // Test that the page requires authentication
        $this->browse(static function (Browser $browser) {
            $browser->visit('/shared-folder/abc')->on(new Home());
        });
    }

    /**
     * Test shared folder list page (unauthenticated)
     */
    public function testListUnauth(): void
    {
        // Test that the page requires authentication
        $this->browse(static function (Browser $browser) {
            $browser->visit('/shared-folders')->on(new Home());
        });
    }

    /**
     * Test shared folders list page
     */
    public function testList(): void
    {
        // Make sure the first folder is active
        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');
        $folder->status = SharedFolder::STATUS_NEW | SharedFolder::STATUS_ACTIVE
            | SharedFolder::STATUS_LDAP_READY | SharedFolder::STATUS_IMAP_READY;
        $folder->save();

        // Log on the user
        $this->browse(static function (Browser $browser) {
            $browser->visit(new Home())
                ->submitLogon('john@kolab.org', 'simple123', true)
                ->on(new Dashboard())
                ->assertSeeIn('@links .link-shared-folders', 'Shared folders')
                ->click('@links .link-shared-folders')
                ->on(new SharedFolderList())
                ->whenAvailable('@table', static function (Browser $browser) {
                    $count = in_array('event', config('app.shared_folder_types')) ? 3 : 1;

                    $browser->waitFor('tbody tr')
                        ->assertElementsCount('thead th', 2)
                        ->assertSeeIn('thead tr th:nth-child(1)', 'Name')
                        ->assertSeeIn('thead tr th:nth-child(2)', 'Type')
                        ->assertElementsCount('tbody tr', $count)
                        ->assertMissing('tfoot');

                    if ($count == 1) {
                        $browser->assertSeeIn('tbody tr:nth-child(1) td:nth-child(1) a', 'Library')
                            ->assertSeeIn('tbody tr:nth-child(1) td:nth-child(2)', 'Mail')
                            ->assertText('tbody tr:nth-child(1) td:nth-child(1) svg.text-success title', 'Active');
                    } else {
                        $browser->assertSeeIn('tbody tr:nth-child(1) td:nth-child(1) a', 'Calendar')
                            ->assertSeeIn('tbody tr:nth-child(1) td:nth-child(2)', 'Calendar')
                            ->assertSeeIn('tbody tr:nth-child(2) td:nth-child(1) a', 'Contacts')
                            ->assertSeeIn('tbody tr:nth-child(2) td:nth-child(2)', 'Address Book')
                            ->assertSeeIn('tbody tr:nth-child(3) td:nth-child(1) a', 'Library')
                            ->assertSeeIn('tbody tr:nth-child(3) td:nth-child(2)', 'Mail')
                            ->assertText('tbody tr:nth-child(3) td:nth-child(1) svg.text-success title', 'Active');
                    }
                });
        });
    }

    /**
     * Test shared folder creation/editing/deleting
     *
     * @depends testList
     */
    public function testCreateUpdateDelete(): void
    {
        $count = in_array('event', config('app.shared_folder_types')) ? 3 : 1;

        $this->browse(function (Browser $browser) use ($count) {
            $cfg = ['app.shared_folder_types' => ['mail', 'event', 'task', 'contact']];

            // Create a folder
            $browser->withConfig($cfg)
                ->visit(new SharedFolderList())
                ->assertSeeIn('button.shared-folder-new', 'Create folder')
                ->click('button.shared-folder-new')
                ->on(new SharedFolderInfo())
                ->assertSeeIn('#folder-info .card-title', 'New shared folder')
                ->assertSeeIn('@nav #tab-general', 'General')
                ->assertMissing('@nav #tab-settings')
                ->with('@general', static function (Browser $browser) use ($cfg) {
                    // Assert form content
                    $browser->assertMissing('#status')
                        ->assertFocused('#name')
                        ->assertSeeIn('div.row:nth-child(1) label', 'Name')
                        ->assertValue('div.row:nth-child(1) input[type=text]', '')
                        ->assertSeeIn('div.row:nth-child(2) label', 'Type')
                        ->assertSelectHasOptions('div.row:nth-child(2) select', $cfg['app.shared_folder_types'])
                        ->assertValue('div.row:nth-child(2) select', 'mail')
                        ->assertSeeIn('div.row:nth-child(3) label', 'Domain')
                        ->assertSelectHasOptions('div.row:nth-child(3) select', ['kolab.org'])
                        ->assertValue('div.row:nth-child(3) select', 'kolab.org')
                        ->assertSeeIn('div.row:nth-child(4) label', 'Email Addresses')
                        ->with(new ListInput('#aliases'), static function (Browser $browser) {
                            $browser->assertListInputValue([])
                                ->assertValue('@input', '');
                        })
                        ->assertSeeIn('div.row:nth-child(5) label', 'Subscriptions')
                        ->with('@skus', static function ($browser) {
                            $browser->assertElementsCount('tbody tr', 1)
                                ->assertSeeIn('tbody tr:nth-child(1) td.name', 'Shared Folder')
                                ->assertSeeIn('tbody tr:nth-child(1) td.price', '0,89 CHF/month')
                                ->assertChecked('tbody tr:nth-child(1) td.selection input')
                                ->assertDisabled('tbody tr:nth-child(1) td.selection input')
                                ->assertTip(
                                    'tbody tr:nth-child(1) td.buttons button',
                                    'A shared folder'
                                );
                        })
                        ->assertSeeIn('button[type=submit]', 'Submit');
                })
                // Test error conditions
                ->type('#name', str_repeat('A', 192))
                ->select('#type', 'event')
                ->assertMissing('#aliases')
                ->click('@general button[type=submit]')
                ->waitFor('#name + .invalid-feedback')
                ->assertSeeIn('#name + .invalid-feedback', 'The name may not be greater than 191 characters.')
                ->assertFocused('#name')
                ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                // Test error handling on aliases input
                ->type('#name', 'Test Folder')
                ->select('#type', 'mail')
                ->with(new ListInput('#aliases'), static function (Browser $browser) {
                    $browser->addListEntry('folder-alias@unknown');
                })
                ->click('@general button[type=submit]')
                ->assertMissing('#name + .invalid-feedback')
                ->waitFor('#aliases + .invalid-feedback')
                ->with(new ListInput('#aliases'), static function (Browser $browser) {
                    $browser->assertFormError(1, "The specified domain is invalid.", true);
                })
                ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                // Test successful folder creation
                ->select('#type', 'event')
                ->click('@general button[type=submit]')
                ->assertToast(Toast::TYPE_SUCCESS, 'Shared folder created successfully.')
                ->on(new SharedFolderList())
                ->assertElementsCount('@table tbody tr', $count + 1);

            $this->assertSame(1, SharedFolder::where('name', 'Test Folder')->count());
            $this->assertSame(0, SharedFolder::where('name', 'Test Folder')->first()->aliases()->count());

            // Test folder update
            $browser->click('@table tr:nth-child(' . ($count + 1) . ') td:first-child a')
                ->on(new SharedFolderInfo())
                ->assertSeeIn('#folder-info .card-title', 'Shared folder')
                ->with('@general', static function (Browser $browser) {
                    // Assert form content
                    $browser->assertFocused('#name')
                        ->assertSeeIn('div.row:nth-child(1) label', 'Status')
                        ->assertSeeIn('div.row:nth-child(1) span.text-danger', 'Not Ready')
                        ->assertSeeIn('div.row:nth-child(2) label', 'Name')
                        ->assertValue('div.row:nth-child(2) input[type=text]', 'Test Folder')
                        ->assertSeeIn('div.row:nth-child(3) label', 'Type')
                        ->assertSelected('div.row:nth-child(3) select:disabled', 'event')
                        ->assertMissing('#aliases')
                        ->assertSeeIn('button[type=submit]', 'Submit');
                })
                // Test error handling
                ->type('#name', str_repeat('A', 192))
                ->click('@general button[type=submit]')
                ->waitFor('#name + .invalid-feedback')
                ->assertSeeIn('#name + .invalid-feedback', 'The name may not be greater than 191 characters.')
                ->assertVisible('#name.is-invalid')
                ->assertFocused('#name')
                ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                // Test successful update
                ->type('#name', 'Test Folder Update')
                ->click('@general button[type=submit]')
                ->assertToast(Toast::TYPE_SUCCESS, 'Shared folder updated successfully.')
                ->on(new SharedFolderList())
                ->assertElementsCount('@table tbody tr', $count + 1)
                ->assertSeeIn('@table tr:nth-child(' . ($count + 1) . ') td:first-child a', 'Test Folder Update');

            $this->assertSame(1, SharedFolder::where('name', 'Test Folder Update')->count());

            // Test folder deletion
            $browser->click('@table tr:nth-child(' . ($count + 1) . ') td:first-child a')
                ->on(new SharedFolderInfo())
                ->assertSeeIn('button.button-delete', 'Delete folder')
                ->click('button.button-delete')
                ->assertToast(Toast::TYPE_SUCCESS, 'Shared folder deleted successfully.')
                ->on(new SharedFolderList())
                ->assertElementsCount('@table tbody tr', $count);

            $this->assertNull(SharedFolder::where('name', 'Test Folder Update')->first());
        });

        // Test creation/updating a mail folder with mail aliases
        $this->browse(function (Browser $browser) use ($count) {
            $browser->on(new SharedFolderList())
                ->click('button.shared-folder-new')
                ->on(new SharedFolderInfo())
                ->type('#name', 'Test Folder2')
                ->with(new ListInput('#aliases'), static function (Browser $browser) {
                    $browser->addListEntry('folder-alias1@kolab.org')
                        ->addListEntry('folder-alias2@kolab.org');
                })
                ->click('@general button[type=submit]')
                ->assertToast(Toast::TYPE_SUCCESS, 'Shared folder created successfully.')
                ->on(new SharedFolderList())
                ->assertElementsCount('@table tbody tr', $count + 1);

            $folder = SharedFolder::where('name', 'Test Folder2')->first();

            $this->assertSame(
                ['folder-alias1@kolab.org', 'folder-alias2@kolab.org'],
                $folder->aliases()->pluck('alias')->all()
            );

            // Test folder update
            $browser->click('@table tr:nth-child(' . ($count + 1) . ') td:first-child a')
                ->on(new SharedFolderInfo())
                ->with('@general', static function (Browser $browser) {
                    // Assert form content
                    $browser->assertFocused('#name')
                        ->assertValue('div.row:nth-child(2) input[type=text]', 'Test Folder2')
                        ->assertSelected('div.row:nth-child(3) select:disabled', 'mail')
                        ->with(new ListInput('#aliases'), static function (Browser $browser) {
                            $browser->assertListInputValue(['folder-alias1@kolab.org', 'folder-alias2@kolab.org'])
                                ->assertValue('@input', '');
                        })
                        ->with('@skus', static function ($browser) {
                            $browser->assertElementsCount('tbody tr', 1)
                                ->assertSeeIn('tbody tr:nth-child(1) td.name', 'Shared Folder')
                                ->assertSeeIn('tbody tr:nth-child(1) td.price', '0,89 CHF/month')
                                ->assertChecked('tbody tr:nth-child(1) td.selection input')
                                ->assertDisabled('tbody tr:nth-child(1) td.selection input')
                                ->assertTip(
                                    'tbody tr:nth-child(1) td.buttons button',
                                    'A shared folder'
                                );
                        });
                })
                // change folder name, and remove one alias
                ->type('#name', 'Test Folder Update2')
                ->with(new ListInput('#aliases'), static function (Browser $browser) {
                    $browser->removeListEntry(2);
                })
                ->click('@general button[type=submit]')
                ->assertToast(Toast::TYPE_SUCCESS, 'Shared folder updated successfully.');

            $folder->refresh();
            $this->assertSame('Test Folder Update2', $folder->name);
            $this->assertSame(['folder-alias1@kolab.org'], $folder->aliases()->pluck('alias')->all());
        });
    }

    /**
     * Test shared folder status
     *
     * @depends testList
     */
    public function testStatus(): void
    {
        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');
        $folder->status = SharedFolder::STATUS_NEW | SharedFolder::STATUS_ACTIVE | SharedFolder::STATUS_LDAP_READY;
        $folder->created_at = \now();
        $folder->save();

        $this->assertFalse($folder->isImapReady());

        $this->browse(static function ($browser) use ($folder) {
            // Test auto-refresh
            $browser->visit('/shared-folder/' . $folder->id)
                ->on(new SharedFolderInfo())
                ->with(new Status(), static function ($browser) {
                    $browser->assertSeeIn('@body', 'We are preparing the shared folder')
                        ->assertProgress(\config('app.with_ldap') ? 85 : 80, 'Creating a shared folder...', 'pending')
                        ->assertMissing('@refresh-button')
                        ->assertMissing('@refresh-text')
                        ->assertMissing('#status-link')
                        ->assertMissing('#status-verify');
                });

            $folder->status |= SharedFolder::STATUS_IMAP_READY;
            $folder->save();

            // Test Verify button
            $browser->waitUntilMissing('@status', 10);
        });

        // TODO: Test all shared folder statuses on the list
    }

    /**
     * Test shared folder settings
     */
    public function testSettings(): void
    {
        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');
        $folder->setSetting('acl', null);

        $this->browse(static function ($browser) use ($folder) {
            $aclInput = new AclInput('@settings #acl');
            // Test auto-refresh
            $browser->visit('/shared-folder/' . $folder->id)
                ->on(new SharedFolderInfo())
                ->assertSeeIn('@nav #tab-general', 'General')
                ->assertSeeIn('@nav #tab-settings', 'Settings')
                ->click('@nav #tab-settings')
                ->with('@settings form', static function (Browser $browser) {
                    // Assert form content
                    $browser->assertSeeIn('div.row:nth-child(1) label', 'Access rights')
                        ->assertSeeIn('div.row:nth-child(1) #acl-hint', 'permissions')
                        ->assertSeeIn('button[type=submit]', 'Submit');
                })
                // Test the AclInput widget
                ->with($aclInput, static function (Browser $browser) {
                    $browser->assertAclValue([])
                        ->addAclEntry('anyone, read-only')
                        ->addAclEntry('test, read-write')
                        ->addAclEntry('john@kolab.org, full')
                        ->assertAclValue([
                            'anyone, read-only',
                            'test, read-write',
                            'john@kolab.org, full',
                        ]);
                })
                // Test error handling
                ->click('@settings button[type=submit]')
                ->with($aclInput, static function (Browser $browser) {
                    $browser->assertFormError(2, 'The specified email address is invalid.');
                })
                ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                // Test successful update
                ->with($aclInput, static function (Browser $browser) {
                    $browser->removeAclEntry(2)
                        ->assertAclValue([
                            'anyone, read-only',
                            'john@kolab.org, full',
                        ])
                        ->updateAclEntry(2, 'jack@kolab.org, read-write')
                        ->assertAclValue([
                            'anyone, read-only',
                            'jack@kolab.org, read-write',
                        ]);
                })
                ->click('@settings button[type=submit]')
                ->assertToast(Toast::TYPE_SUCCESS, 'Shared folder settings updated successfully.')
                ->assertMissing('.invalid-feedback')
                // Refresh the page and check if everything was saved
                ->refresh()
                ->on(new SharedFolderInfo())
                ->click('@nav #tab-settings')
                ->with($aclInput, static function (Browser $browser) {
                    $browser->assertAclValue([
                        'anyone, read-only',
                        'jack@kolab.org, read-write',
                    ]);
                });
        });
    }
}
