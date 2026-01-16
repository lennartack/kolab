<?php

namespace Tests\Browser\Reseller;

use App\Domain;
use App\Plan;
use App\Utils;
use Illuminate\Support\Facades\Queue;
use Tests\Browser;
use Tests\Browser\Components\Toast;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\Home;
use Tests\TestCaseDusk;

class DashboardTest extends TestCaseDusk
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useResellerUrl();

        $jack = $this->getTestUser('jack@kolab.org');
        $jack->setSetting('external_email', null);

        $this->deleteTestUser('test@testsearch.com');
        $this->deleteTestDomain('testsearch.com');
    }

    protected function tearDown(): void
    {
        $jack = $this->getTestUser('jack@kolab.org');
        $jack->setSetting('external_email', null);

        $this->deleteTestUser('test@testsearch.com');
        $this->deleteTestDomain('testsearch.com');

        parent::tearDown();
    }

    /**
     * Test user search
     */
    public function testSearch(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit(new Home())
                ->submitLogon('reseller@' . \config('app.domain'), Utils::generatePassphrase(), true)
                ->on(new Dashboard())
                ->assertFocused('@search input')
                ->assertMissing('@search table');

            // Test search with no results
            $browser->type('@search input', 'unknown')
                ->click('@search form button')
                ->assertToast(Toast::TYPE_INFO, '0 user accounts have been found.')
                ->assertMissing('@search table');

            $john = $this->getTestUser('john@kolab.org');
            $jack = $this->getTestUser('jack@kolab.org');
            $jack->setSetting('external_email', 'john.doe.external@gmail.com');

            // Test search with multiple results
            $browser->type('@search input', 'john.doe.external@gmail.com')
                ->click('@search form button')
                ->assertToast(Toast::TYPE_INFO, '2 user accounts have been found.')
                ->whenAvailable('@search table', static function (Browser $browser) use ($john, $jack) {
                    $browser->assertElementsCount('tbody tr', 2)
                        ->with('tbody tr:first-child', static function (Browser $browser) use ($jack) {
                            $browser->assertSeeIn('td:nth-child(1) a', $jack->email)
                                ->assertSeeIn('td:nth-child(2) a', $jack->id);

                            if ($browser->isPhone()) {
                                $browser->assertMissing('td:nth-child(3)');
                            } else {
                                $browser->assertVisible('td:nth-child(3)')
                                    ->assertTextRegExp('td:nth-child(3)', '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/')
                                    ->assertVisible('td:nth-child(4)')
                                    ->assertText('td:nth-child(4)', '');
                            }
                        })
                        ->with('tbody tr:last-child', static function (Browser $browser) use ($john) {
                            $browser->assertSeeIn('td:nth-child(1) a', $john->email)
                                ->assertSeeIn('td:nth-child(2) a', $john->id);

                            if ($browser->isPhone()) {
                                $browser->assertMissing('td:nth-child(3)');
                            } else {
                                $browser->assertVisible('td:nth-child(3)')
                                    ->assertTextRegExp('td:nth-child(3)', '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/')
                                    ->assertVisible('td:nth-child(4)')
                                    ->assertText('td:nth-child(4)', '');
                            }
                        });
                });

            // Test search with single record result -> redirect to user page
            $browser->type('@search input', 'kolab.org')
                ->click('@search form button')
                ->assertMissing('@search table')
                ->waitForLocation('/user/' . $john->id)
                ->waitUntilMissing('.app-loader')
                ->whenAvailable('#user-info', static function (Browser $browser) use ($john) {
                    $browser->assertSeeIn('.card-title', $john->email);
                });
        });
    }

    /**
     * Test user search deleted user/domain
     */
    public function testSearchDeleted(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit(new Home())
                ->submitLogon('reseller@' . \config('app.domain'), Utils::generatePassphrase(), true)
                ->on(new Dashboard())
                ->assertFocused('@search input')
                ->assertMissing('@search table');

            // Deleted users/domains
            $domain = $this->getTestDomain('testsearch.com', ['type' => Domain::TYPE_EXTERNAL]);
            $user = $this->getTestUser('test@testsearch.com');
            $plan = Plan::where('title', 'group')->first();
            $user->assignPlan($plan, $domain);
            $user->setAliases(['alias@testsearch.com']);
            Queue::fake();
            $user->delete();

            // Test search with multiple results
            $browser->type('@search input', 'testsearch.com')
                ->click('@search form button')
                ->assertToast(Toast::TYPE_INFO, '1 user accounts have been found.')
                ->whenAvailable('@search table', static function (Browser $browser) use ($user) {
                    $browser->assertElementsCount('tbody tr', 1)
                        ->assertVisible('tbody tr:first-child.text-secondary')
                        ->with('tbody tr:first-child', static function (Browser $browser) use ($user) {
                            $browser->assertSeeIn('td:nth-child(1) a', $user->email)
                                ->assertSeeIn('td:nth-child(2) a', $user->id);

                            if ($browser->isPhone()) {
                                $browser->assertMissing('td:nth-child(3)');
                            } else {
                                $browser->assertVisible('td:nth-child(3)')
                                    ->assertTextRegExp('td:nth-child(3)', '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/')
                                    ->assertVisible('td:nth-child(4)')
                                    ->assertTextRegExp('td:nth-child(4)', '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/');
                            }
                        });
                });
        });
    }
}
