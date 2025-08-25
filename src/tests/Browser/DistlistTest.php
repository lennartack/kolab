<?php

namespace Tests\Browser;

use App\Group;
use Tests\Browser;
use Tests\Browser\Components\ListInput;
use Tests\Browser\Components\Status;
use Tests\Browser\Components\Toast;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\DistlistInfo;
use Tests\Browser\Pages\DistlistList;
use Tests\Browser\Pages\Home;
use Tests\TestCaseDusk;

class DistlistTest extends TestCaseDusk
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestGroup('group-test@kolab.org');
    }

    protected function tearDown(): void
    {
        $this->deleteTestGroup('group-test@kolab.org');

        parent::tearDown();
    }

    /**
     * Test distlist info page (unauthenticated)
     */
    public function testInfoUnauth(): void
    {
        // Test that the page requires authentication
        $this->browse(static function (Browser $browser) {
            $browser->visit('/distlist/abc')->on(new Home());
        });
    }

    /**
     * Test distlist list page (unauthenticated)
     */
    public function testListUnauth(): void
    {
        // Test that the page requires authentication
        $this->browse(static function (Browser $browser) {
            $browser->visit('/distlists')->on(new Home());
        });
    }

    /**
     * Test distlist list page
     */
    public function testList(): void
    {
        // Create a single group
        $john = $this->getTestUser('john@kolab.org');
        $group = $this->getTestGroup('group-test@kolab.org', ['name' => 'Test Group']);
        $group->assignToWallet($john->wallets->first());

        // Log on the user
        $this->browse(static function (Browser $browser) {
            $browser->visit(new Home())
                ->submitLogon('john@kolab.org', 'simple123', true)
                ->on(new Dashboard())
                ->assertSeeIn('@links .link-distlists', 'Distribution lists')
                ->click('@links .link-distlists')
                ->on(new DistlistList())
                ->whenAvailable('@table', static function (Browser $browser) {
                    $browser->waitFor('tbody tr')
                        ->assertSeeIn('thead tr th:nth-child(1)', 'Name')
                        ->assertSeeIn('thead tr th:nth-child(2)', 'Email')
                        ->assertElementsCount('tbody tr', 1)
                        ->assertSeeIn('tbody tr:nth-child(1) td:nth-child(1) a', 'Test Group')
                        ->assertText('tbody tr:nth-child(1) td:nth-child(1) svg.text-danger title', 'Not Ready')
                        ->assertSeeIn('tbody tr:nth-child(1) td:nth-child(2) a', 'group-test@kolab.org')
                        ->assertMissing('tfoot');
                });
        });
    }

    /**
     * Test distlist creation/editing/deleting
     *
     * @depends testList
     */
    public function testCreateUpdateDelete(): void
    {
        $this->browse(function (Browser $browser) {
            // Create a group
            $browser->visit(new DistlistList())
                ->assertSeeIn('button.distlist-new', 'Create list')
                ->click('button.distlist-new')
                ->on(new DistlistInfo())
                ->assertSeeIn('#distlist-info .card-title', 'New distribution list')
                ->assertSeeIn('@nav #tab-general', 'General')
                ->assertMissing('@nav #tab-settings')
                ->with('@general', static function (Browser $browser) {
                    // Assert form content
                    $browser->assertMissing('#status')
                        ->assertFocused('#name')
                        ->assertSeeIn('div.row:nth-child(1) label', 'Name')
                        ->assertValue('div.row:nth-child(1) input[type=text]', '')
                        ->assertSeeIn('div.row:nth-child(2) label', 'Email')
                        ->assertValue('div.row:nth-child(2) input[type=text]', '')
                        ->assertSeeIn('div.row:nth-child(3) label', 'Recipients')
                        ->assertVisible('div.row:nth-child(3) .list-input')
                        ->with(new ListInput('#members'), static function (Browser $browser) {
                            $browser->assertListInputValue([])
                                ->assertValue('@input', '');
                        })
                        ->assertSeeIn('div.row:nth-child(4) label', 'Subscriptions')
                        ->with('@skus', static function ($browser) {
                            $browser->assertElementsCount('tbody tr', 1)
                                ->assertSeeIn('tbody tr:nth-child(1) td.name', 'Distribution list')
                                ->assertSeeIn('tbody tr:nth-child(1) td.price', '0,00 CHF/month')
                                ->assertChecked('tbody tr:nth-child(1) td.selection input')
                                ->assertDisabled('tbody tr:nth-child(1) td.selection input')
                                ->assertTip(
                                    'tbody tr:nth-child(1) td.buttons button',
                                    'Mail distribution list'
                                );
                        })
                        ->assertSeeIn('button[type=submit]', 'Submit');
                })
                // Test error conditions
                ->type('#name', str_repeat('A', 192))
                ->type('#email', 'group-test@kolabnow.com')
                ->click('@general button[type=submit]')
                ->waitFor('#members + .invalid-feedback')
                ->assertSeeIn('#email + .invalid-feedback', 'The specified domain is not available.')
                ->assertSeeIn('#name + .invalid-feedback', 'The name may not be greater than 191 characters.')
                ->assertSeeIn('#members + .invalid-feedback', 'At least one recipient is required.')
                ->assertFocused('#name')
                ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                // Test successful group creation
                ->type('#name', 'Test Group')
                ->type('#email', 'group-test@kolab.org')
                ->with(new ListInput('#members'), static function (Browser $browser) {
                    $browser->addListEntry('test1@gmail.com')
                        ->addListEntry('test2@gmail.com');
                })
                ->click('@general button[type=submit]')
                ->assertToast(Toast::TYPE_SUCCESS, 'Distribution list created successfully.')
                ->on(new DistlistList())
                ->assertElementsCount('@table tbody tr', 1);

            // Test group update
            $browser->click('@table tr:nth-child(1) td:first-child a')
                ->on(new DistlistInfo())
                ->assertSeeIn('#distlist-info .card-title', 'Distribution list')
                ->with('@general', static function (Browser $browser) {
                    // Assert form content
                    $browser->assertFocused('#name')
                        ->assertSeeIn('div.row:nth-child(1) label', 'Status')
                        ->assertSeeIn('div.row:nth-child(1) span.text-danger', 'Not Ready')
                        ->assertSeeIn('div.row:nth-child(2) label', 'Name')
                        ->assertValue('div.row:nth-child(2) input[type=text]', 'Test Group')
                        ->assertSeeIn('div.row:nth-child(3) label', 'Email')
                        ->assertValue('div.row:nth-child(3) input[type=text]:disabled', 'group-test@kolab.org')
                        ->assertSeeIn('div.row:nth-child(4) label', 'Recipients')
                        ->assertVisible('div.row:nth-child(4) .list-input')
                        ->with(new ListInput('#members'), static function (Browser $browser) {
                            $browser->assertListInputValue(['test1@gmail.com', 'test2@gmail.com'])
                                ->assertValue('@input', '');
                        })
                        ->assertSeeIn('div.row:nth-child(5) label', 'Subscriptions')
                        ->with('@skus', static function ($browser) {
                            $browser->assertElementsCount('tbody tr', 1)
                                ->assertSeeIn('tbody tr:nth-child(1) td.name', 'Distribution list')
                                ->assertSeeIn('tbody tr:nth-child(1) td.price', '0,00 CHF/month')
                                ->assertChecked('tbody tr:nth-child(1) td.selection input')
                                ->assertDisabled('tbody tr:nth-child(1) td.selection input')
                                ->assertTip(
                                    'tbody tr:nth-child(1) td.buttons button',
                                    'Mail distribution list'
                                );
                        })
                        ->assertSeeIn('button[type=submit]', 'Submit');
                })
                // Test error handling
                ->with(new ListInput('#members'), static function (Browser $browser) {
                    $browser->addListEntry('invalid address');
                })
                ->click('@general button[type=submit]')
                ->waitFor('#members + .invalid-feedback')
                ->assertSeeIn('#members + .invalid-feedback', 'The specified email address is invalid.')
                ->assertVisible('#members .input-group:nth-child(4) input.is-invalid')
                ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                // Test successful update
                ->with(new ListInput('#members'), static function (Browser $browser) {
                    $browser->removeListEntry(3)->removeListEntry(2);
                })
                ->click('@general button[type=submit]')
                ->assertToast(Toast::TYPE_SUCCESS, 'Distribution list updated successfully.')
                ->assertMissing('.invalid-feedback')
                ->on(new DistlistList())
                ->assertElementsCount('@table tbody tr', 1);

            $group = Group::where('email', 'group-test@kolab.org')->first();
            $this->assertSame(['test1@gmail.com'], $group->getAddresses());

            // Test group deletion
            $browser->click('@table tr:nth-child(1) td:first-child a')
                ->on(new DistlistInfo())
                ->assertSeeIn('button.button-delete', 'Delete list')
                ->click('button.button-delete')
                ->assertToast(Toast::TYPE_SUCCESS, 'Distribution list deleted successfully.')
                ->on(new DistlistList())
                ->assertElementsCount('@table tbody tr', 0)
                ->assertVisible('@table tfoot');

            $this->assertNull(Group::where('email', 'group-test@kolab.org')->first());
        });
    }

    /**
     * Test distribution list status
     *
     * @depends testList
     */
    public function testStatus(): void
    {
        if (!\config('app.with_ldap')) {
            $this->markTestSkipped();
        }

        $john = $this->getTestUser('john@kolab.org');
        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($john->wallets->first());
        $group->status = Group::STATUS_NEW | Group::STATUS_ACTIVE;
        $group->save();

        $this->assertFalse($group->isLdapReady());

        $this->browse(static function ($browser) use ($group) {
            // Test auto-refresh
            $browser->visit('/distlist/' . $group->id)
                ->on(new DistlistInfo())
                ->with(new Status(), static function ($browser) {
                    $browser->assertSeeIn('@body', 'We are preparing the distribution list')
                        ->assertProgress(83, 'Creating a distribution list...', 'pending')
                        ->assertMissing('@refresh-button')
                        ->assertMissing('@refresh-text')
                        ->assertMissing('#status-link')
                        ->assertMissing('#status-verify');
                });

            $group->status |= Group::STATUS_LDAP_READY;
            $group->save();

            // Test Verify button
            $browser->waitUntilMissing('@status', 10);
        });

        // TODO: Test all group statuses on the list
    }

    /**
     * Test distribution list settings
     */
    public function testSettings(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $group = $this->getTestGroup('group-test@kolab.org');
        $group->assignToWallet($john->wallets->first());
        $group->status = Group::STATUS_NEW | Group::STATUS_ACTIVE;
        $group->save();

        $this->browse(static function ($browser) use ($group) {
            // Test auto-refresh
            $browser->visit('/distlist/' . $group->id)
                ->on(new DistlistInfo())
                ->assertSeeIn('@nav #tab-general', 'General')
                ->assertSeeIn('@nav #tab-settings', 'Settings')
                ->click('@nav #tab-settings')
                ->with('@settings form', static function (Browser $browser) {
                    // Assert form content
                    $browser->assertSeeIn('div.row:nth-child(1) label', 'Sender Access List')
                        ->assertVisible('div.row:nth-child(1) .list-input')
                        ->with(new ListInput('#sender-policy'), static function (Browser $browser) {
                            $browser->assertListInputValue([])
                                ->assertValue('@input', '');
                        })
                        ->assertSeeIn('button[type=submit]', 'Submit');
                })
                // Test error handling
                ->with(new ListInput('#sender-policy'), static function (Browser $browser) {
                    $browser->addListEntry('test.com');
                })
                ->click('@settings button[type=submit]')
                ->assertToast(Toast::TYPE_SUCCESS, 'Distribution list settings updated successfully.')
                ->assertMissing('.invalid-feedback')
                ->refresh()
                ->on(new DistlistInfo())
                ->click('@nav #tab-settings')
                ->with('@settings form', static function (Browser $browser) {
                    $browser->with(new ListInput('#sender-policy'), static function (Browser $browser) {
                        $browser->assertListInputValue(['test.com'])
                            ->assertValue('@input', '');
                    });
                });
        });
    }
}
