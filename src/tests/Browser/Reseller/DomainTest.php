<?php

namespace Tests\Browser\Reseller;

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

        self::useResellerUrl();
    }

    protected function tearDown(): void
    {
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
            $reseller = $this->getTestUser('reseller@' . \config('app.domain'));
            $user = $this->getTestUser('john@kolab.org');
            $user_page = new UserPage($user->id);

            // Goto the domain page
            $browser->visit(new Home())
                ->submitLogon('reseller@' . \config('app.domain'), Utils::generatePassphrase(), true)
                ->on(new Dashboard())
                ->visit($user_page)
                ->on($user_page)
                ->click('@nav #tab-domains')
                ->pause(1000)
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
                ->assertElementsCount('@nav a', 3)
                ->assertSeeIn('@nav #tab-settings', 'Settings')
                ->assertSeeIn('@nav #tab-history', 'History');

            // Assert Configuration tab
            $browser->assertSeeIn('@nav #tab-config', 'Configuration')
                ->with('@domain-config', static function (Browser $browser) {
                    $browser->assertSeeIn('pre#dns-confirm', 'kolab.org.')
                        ->assertSeeIn('pre#dns-config', 'kolab.org.');
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
