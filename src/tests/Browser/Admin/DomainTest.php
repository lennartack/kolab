<?php

namespace Tests\Browser\Admin;

use App\Domain;
use App\Entitlement;
use App\EventLog;
use App\Sku;
use App\Utils;
use Tests\Browser;
use Tests\Browser\Components\Dialog;
use Tests\Browser\Components\Toast;
use Tests\Browser\Pages\Admin\Domain as DomainPage;
use Tests\Browser\Pages\Admin\User as UserPage;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\Home;
use Tests\TestCaseDusk;

class DomainTest extends TestCaseDusk
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('test1@domainscontroller.com');
        $this->deleteTestDomain('domainscontroller.com');

        EventLog::query()->delete();

        self::useAdminUrl();
    }

    protected function tearDown(): void
    {
        $domain = $this->getTestDomain('kolab.org');
        $domain->setSetting('spf_whitelist', null);

        $this->deleteTestUser('test1@domainscontroller.com');
        $this->deleteTestDomain('domainscontroller.com');

        EventLog::query()->delete();

        parent::tearDown();
    }

    /**
     * Test domain info page (unauthenticated)
     */
    public function testDomainUnauth(): void
    {
        // Test that the page requires authentication
        $this->browse(function (Browser $browser) {
            $domain = $this->getTestDomain('kolab.org');
            $browser->visit('/domain/' . $domain->id)->on(new Home());
        });
    }

    /**
     * Test domain info page
     */
    public function testDomainInfo(): void
    {
        $this->browse(function (Browser $browser) {
            $domain = $this->getTestDomain('kolab.org');
            $domain_page = new DomainPage($domain->id);
            $john = $this->getTestUser('john@kolab.org');
            $user_page = new UserPage($john->id);

            $domain->setSetting('spf_whitelist', null);

            $event1 = EventLog::createFor($domain, EventLog::TYPE_SUSPENDED, 'Event 1');
            $event2 = EventLog::createFor($domain, EventLog::TYPE_UNSUSPENDED, 'Event 2', ['test' => 'test-data']);
            $event2->refresh();
            $event1->created_at = (clone $event2->created_at)->subDay();
            $event1->save();

            // Goto the domain page
            $browser->visit(new Home())
                ->submitLogon('jeroen@jeroen.jeroen', Utils::generatePassphrase(), true)
                ->on(new Dashboard())
                ->visit($user_page)
                ->on($user_page)
                ->click('@nav #tab-domains')
                ->waitFor('@user-domains table tbody')
                ->click('@user-domains table tbody tr:first-child td a');

            $browser->on($domain_page)
                ->assertSeeIn('@domain-info .card-title', 'kolab.org')
                ->with('@domain-info form', static function (Browser $browser) use ($domain) {
                    $browser->assertElementsCount('.row', 2)
                        ->assertSeeIn('.row:nth-child(1) label', 'ID (Created)')
                        ->assertSeeIn('.row:nth-child(1) #domainid', "{$domain->id} ({$domain->created_at})")
                        ->assertSeeIn('.row:nth-child(2) label', 'Status')
                        ->assertSeeIn('.row:nth-child(2) #status span.text-success', 'Active');
                });

            // Some tabs are loaded in background, wait a second
            $browser->pause(500)
                ->assertElementsCount('@nav a', 3);

            // Assert Configuration tab
            $browser->assertSeeIn('@nav #tab-config', 'Configuration')
                ->with('@domain-config', static function (Browser $browser) {
                    $browser->assertSeeIn('pre#dns-confirm', 'kolab.org.')
                        ->assertSeeIn('pre#dns-config', 'kolab.org.');
                });

            // Assert Settings tab
            $browser->assertSeeIn('@nav #tab-settings', 'Settings')
                ->click('@nav #tab-settings')
                ->with('@domain-settings form', static function (Browser $browser) {
                    $browser->assertElementsCount('.row', 1)
                        ->assertSeeIn('.row:first-child label', 'SPF Whitelist')
                        ->assertSeeIn('.row:first-child .form-control-plaintext', 'none');
                });

            // Assert non-empty SPF whitelist
            $domain->setSetting('spf_whitelist', json_encode(['.test1.com', '.test2.com']));

            $browser->refresh()
                ->on($domain_page)
                ->waitFor('@nav #tab-settings')
                ->click('@nav #tab-settings')
                ->whenAvailable('@domain-settings form', static function (Browser $browser) {
                    $browser->assertSeeIn('.row:first-child .form-control-plaintext', '.test1.com, .test2.com');
                });

            // Assert History tab
            $browser->assertSeeIn('@nav #tab-history', 'History')
                ->click('@nav #tab-history')
                ->whenAvailable('@domain-history table', static function (Browser $browser) use ($event1, $event2) {
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
        });
    }

    /**
     * Test suspending/unsuspending a domain
     *
     * @depends testDomainInfo
     */
    public function testSuspendAndUnsuspend(): void
    {
        EventLog::query()->delete();

        $this->browse(function (Browser $browser) {
            $sku_domain = Sku::withEnvTenantContext()->where('title', 'domain-hosting')->first();
            $user = $this->getTestUser('test1@domainscontroller.com');
            $domain = $this->getTestDomain('domainscontroller.com', [
                'status' => Domain::STATUS_NEW | Domain::STATUS_ACTIVE
                    | Domain::STATUS_LDAP_READY | Domain::STATUS_CONFIRMED
                    | Domain::STATUS_VERIFIED,
                'type' => Domain::TYPE_EXTERNAL,
            ]);

            Entitlement::create([
                'wallet_id' => $user->wallets()->first()->id,
                'sku_id' => $sku_domain->id,
                'entitleable_id' => $domain->id,
                'entitleable_type' => Domain::class,
            ]);

            $browser->visit(new DomainPage($domain->id))
                ->assertVisible('@domain-info #button-suspend')
                ->assertMissing('@domain-info #button-unsuspend')
                ->click('@domain-info #button-suspend')
                ->with(new Dialog('#suspend-dialog'), static function (Browser $browser) {
                    $browser->assertSeeIn('@title', 'Suspend')
                        ->assertSeeIn('@button-cancel', 'Cancel')
                        ->assertSeeIn('@button-action', 'Submit')
                        ->type('textarea', 'test suspend')
                        ->click('@button-action');
                })
                ->assertToast(Toast::TYPE_SUCCESS, 'Domain suspended successfully.')
                ->assertSeeIn('@domain-info #status span.text-warning', 'Suspended')
                ->assertMissing('@domain-info #button-suspend');

            $event = EventLog::where('type', EventLog::TYPE_SUSPENDED)->first();
            $this->assertSame('test suspend', $event->comment);
            $this->assertSame((string) $domain->id, (string) $event->object_id);

            $browser->click('@domain-info #button-unsuspend')
                ->with(new Dialog('#suspend-dialog'), static function (Browser $browser) {
                    $browser->assertSeeIn('@title', 'Unsuspend')
                        ->assertSeeIn('@button-cancel', 'Cancel')
                        ->assertSeeIn('@button-action', 'Submit')
                        ->click('@button-action');
                })
                ->assertToast(Toast::TYPE_SUCCESS, 'Domain unsuspended successfully.')
                ->assertSeeIn('@domain-info #status span.text-success', 'Active')
                ->assertVisible('@domain-info #button-suspend')
                ->assertMissing('@domain-info #button-unsuspend');

            $event = EventLog::where('type', EventLog::TYPE_UNSUSPENDED)->first();
            $this->assertNull($event->comment);
            $this->assertSame((string) $domain->id, (string) $event->object_id);
        });
    }
}
