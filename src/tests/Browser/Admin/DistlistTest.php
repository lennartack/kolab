<?php

namespace Tests\Browser\Admin;

use App\EventLog;
use App\Group;
use App\Utils;
use Illuminate\Support\Facades\Queue;
use Tests\Browser;
use Tests\Browser\Components\Dialog;
use Tests\Browser\Components\Toast;
use Tests\Browser\Pages\Admin\Distlist as DistlistPage;
use Tests\Browser\Pages\Admin\User as UserPage;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\Home;
use Tests\TestCaseDusk;

class DistlistTest extends TestCaseDusk
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useAdminUrl();

        $this->deleteTestGroup('group-test@kolab.org');
        EventLog::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestGroup('group-test@kolab.org');
        EventLog::query()->delete();

        parent::tearDown();
    }

    /**
     * Test distlist info page (unauthenticated)
     */
    public function testDistlistUnauth(): void
    {
        // Test that the page requires authentication
        $this->browse(function (Browser $browser) {
            $user = $this->getTestUser('john@kolab.org');
            $group = $this->getTestGroup('group-test@kolab.org');
            $group->assignToWallet($user->wallets->first());

            $browser->visit('/distlist/' . $group->id)->on(new Home());
        });
    }

    /**
     * Test distribution list info page
     */
    public function testInfo(): void
    {
        Queue::fake();

        $this->browse(function (Browser $browser) {
            $user = $this->getTestUser('john@kolab.org');
            $group = $this->getTestGroup('group-test@kolab.org', ['name' => 'Test Group']);
            $group->assignToWallet($user->wallets->first());
            $group->setAddresses(['test1@gmail.com', 'test2@gmail.com'], true);
            $group->setConfig(['sender_policy' => ['test1.com', 'test2.com']]);

            $event1 = EventLog::createFor($group, EventLog::TYPE_SUSPENDED, 'Event 1');
            $event2 = EventLog::createFor($group, EventLog::TYPE_UNSUSPENDED, 'Event 2', ['test' => 'test-data']);
            $event2->refresh();
            $event1->created_at = (clone $event2->created_at)->subDay();
            $event1->save();

            $distlist_page = new DistlistPage($group->id);
            $user_page = new UserPage($user->id);

            // Goto the distlist page
            $browser->visit(new Home())
                ->submitLogon('jeroen@jeroen.jeroen', Utils::generatePassphrase(), true)
                ->on(new Dashboard())
                ->visit($user_page)
                ->on($user_page)
                ->click('@nav #tab-distlists')
                ->waitFor('@user-distlists table tbody')
                ->click('@user-distlists table tbody tr:first-child td a')
                ->on($distlist_page)
                ->assertSeeIn('@distlist-info .card-title', $group->email)
                ->with('@distlist-info form', static function (Browser $browser) use ($group) {
                    $members = $group->getAddresses();
                    $browser->assertElementsCount('.row', 4)
                        ->assertSeeIn('.row:nth-child(1) label', 'ID (Created)')
                        ->assertSeeIn('.row:nth-child(1) #distlistid', "{$group->id} ({$group->created_at})")
                        ->assertSeeIn('.row:nth-child(2) label', 'Status')
                        ->assertSeeIn('.row:nth-child(2) #status.text-danger', 'Not Ready')
                        ->assertSeeIn('.row:nth-child(3) label', 'Name')
                        ->assertSeeIn('.row:nth-child(3) #name', $group->name)
                        ->assertSeeIn('.row:nth-child(4) label', 'Recipients')
                        ->assertSeeIn('.row:nth-child(4) #members', $members[0])
                        ->assertSeeIn('.row:nth-child(4) #members', $members[1]);
                })
                ->assertElementsCount('ul.nav-tabs li', 2)
                ->assertSeeIn('ul.nav-tabs #tab-settings', 'Settings')
                ->with('@distlist-settings form', static function (Browser $browser) {
                    $browser->assertElementsCount('.row', 1)
                        ->assertSeeIn('.row:nth-child(1) label', 'Sender Access List')
                        ->assertSeeIn('.row:nth-child(1) #sender_policy', 'test1.com, test2.com');
                });

            // Assert History tab
            $browser->assertSeeIn('ul.nav-tabs #tab-history', 'History')
                ->click('ul.nav-tabs #tab-history')
                ->whenAvailable('@distlist-history table', static function (Browser $browser) use ($event1, $event2) {
                    $browser->waitFor('tbody tr')->assertElementsCount('tbody tr', 2)
                        // row 1
                        ->assertSeeIn('tr:nth-child(1) td:nth-child(1)', $event2->created_at->toDateTimeString())
                        ->assertSeeIn('tr:nth-child(1) td:nth-child(2)', 'Unsuspended')
                        ->assertSeeIn('tr:nth-child(1) td:nth-child(3)', $event2->comment)
                        ->assertMissing('tr:nth-child(1) td:nth-child(3) div')
                        ->assertMissing('tr:nth-child(1) td:nth-child(3) pre')
                        ->assertMissing('tr:nth-child(1) td:nth-child(3) .btn-less')
                        ->click('tr:nth-child(1) td:nth-child(3) .btn-more')
                        ->assertMissing('tr:nth-child(1) td:nth-child(3) div.email')
                        ->assertSeeIn('tr:nth-child(1) td:nth-child(3) pre', 'test-data')
                        ->assertMissing('tr:nth-child(1) td:nth-child(3) .btn-more')
                        ->click('tr:nth-child(1) td:nth-child(3) .btn-less')
                        ->assertMissing('tr:nth-child(1) td:nth-child(3) div')
                        ->assertMissing('tr:nth-child(1) td:nth-child(3) pre')
                        ->assertMissing('tr:nth-child(1) td:nth-child(3) .btn-less')
                        // row 2
                        ->assertSeeIn('tr:nth-child(2) td:nth-child(1)', $event1->created_at->toDateTimeString())
                        ->assertSeeIn('tr:nth-child(2) td:nth-child(2)', 'Suspended')
                        ->assertSeeIn('tr:nth-child(2) td:nth-child(3)', $event1->comment)
                        ->assertMissing('tr:nth-child(2) td:nth-child(3) .btn-more')
                        ->assertMissing('tr:nth-child(2) td:nth-child(3) .btn-less');
                });

            // Test invalid group identifier
            $browser->visit('/distlist/abc')->assertErrorPage(404);
        });
    }

    /**
     * Test suspending/unsuspending a distribution list
     *
     * @depends testInfo
     */
    public function testSuspendAndUnsuspend(): void
    {
        Queue::fake();
        EventLog::query()->delete();

        $this->browse(function (Browser $browser) {
            $user = $this->getTestUser('john@kolab.org');
            $group = $this->getTestGroup('group-test@kolab.org');
            $group->assignToWallet($user->wallets->first());
            $group->status = Group::STATUS_ACTIVE | Group::STATUS_LDAP_READY;
            $group->save();

            $browser->visit(new DistlistPage($group->id))
                ->assertVisible('@distlist-info #button-suspend')
                ->assertMissing('@distlist-info #button-unsuspend')
                ->assertSeeIn('@distlist-info #status.text-success', 'Active')
                ->click('@distlist-info #button-suspend')
                ->with(new Dialog('#suspend-dialog'), static function (Browser $browser) {
                    $browser->assertSeeIn('@title', 'Suspend')
                        ->assertSeeIn('@button-cancel', 'Cancel')
                        ->assertSeeIn('@button-action', 'Submit')
                        ->type('textarea', 'test suspend')
                        ->click('@button-action');
                })
                ->assertToast(Toast::TYPE_SUCCESS, 'Distribution list suspended successfully.')
                ->assertSeeIn('@distlist-info #status.text-warning', 'Suspended')
                ->assertMissing('@distlist-info #button-suspend');

            $event = EventLog::where('type', EventLog::TYPE_SUSPENDED)->first();
            $this->assertSame('test suspend', $event->comment);
            $this->assertSame((string) $group->id, (string) $event->object_id);

            $browser->click('@distlist-info #button-unsuspend')
                ->with(new Dialog('#suspend-dialog'), static function (Browser $browser) {
                    $browser->assertSeeIn('@title', 'Unsuspend')
                        ->assertSeeIn('@button-cancel', 'Cancel')
                        ->assertSeeIn('@button-action', 'Submit')
                        ->click('@button-action');
                })
                ->assertToast(Toast::TYPE_SUCCESS, 'Distribution list unsuspended successfully.')
                ->assertSeeIn('@distlist-info #status.text-success', 'Active')
                ->assertVisible('@distlist-info #button-suspend')
                ->assertMissing('@distlist-info #button-unsuspend');

            $event = EventLog::where('type', EventLog::TYPE_UNSUSPENDED)->first();
            $this->assertNull($event->comment);
            $this->assertSame((string) $group->id, (string) $event->object_id);
        });
    }
}
