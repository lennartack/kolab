<?php

namespace Tests\Browser;

use App\Package;
use App\Payment;
use App\Plan;
use App\ReferralProgram;
use App\Transaction;
use App\Wallet;
use Carbon\Carbon;
use Tests\Browser;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\Home;
use Tests\Browser\Pages\Wallet as WalletPage;
use Tests\TestCaseDusk;

class WalletTest extends TestCaseDusk
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('wallets-controller@kolabnow.com');

        $john = $this->getTestUser('john@kolab.org');
        Wallet::where('user_id', $john->id)->update(['balance' => -1234, 'currency' => 'CHF']);
        ReferralProgram::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('wallets-controller@kolabnow.com');

        $john = $this->getTestUser('john@kolab.org');
        Wallet::where('user_id', $john->id)->update(['balance' => 0]);
        ReferralProgram::query()->delete();

        parent::tearDown();
    }

    /**
     * Test wallet page (unauthenticated)
     */
    public function testWalletUnauth(): void
    {
        // Test that the page requires authentication
        $this->browse(static function (Browser $browser) {
            $browser->visit('/wallet')->on(new Home());
        });
    }

    /**
     * Test wallet "box" on Dashboard
     */
    public function testDashboard(): void
    {
        // Test that the page requires authentication
        $this->browse(static function (Browser $browser) {
            $browser->visit(new Home())
                ->submitLogon('john@kolab.org', 'simple123', true)
                ->on(new Dashboard())
                ->assertSeeIn('@links .link-wallet svg + span', 'Wallet')
                ->assertSeeIn('@links .link-wallet .badge', '-12,34 CHF');
        });
    }

    /**
     * Test wallet page
     *
     * @depends testDashboard
     */
    public function testWallet(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->click('@links .link-wallet')
                ->on(new WalletPage())
                ->assertSeeIn('#wallet .card-title', 'Account balance -12,34 CHF')
                ->assertSeeIn('#wallet .card-title .text-danger', '-12,34 CHF')
                ->assertSeeIn('#wallet .card-text', 'You are out of credit');
        });
    }

    /**
     * Test Receipts tab
     */
    public function testReceipts(): void
    {
        $user = $this->getTestUser('wallets-controller@kolabnow.com', ['password' => 'simple123']);
        $plan = Plan::withObjectTenantContext($user)->where('title', 'individual')->first();
        $user->assignPlan($plan);
        $wallet = $user->wallets()->first();
        $wallet->payments()->delete();

        // Log out John and log in the test user
        $this->browse(static function (Browser $browser) {
            $browser->visit('/logout')
                ->waitForLocation('/login')
                ->on(new Home())
                ->submitLogon('wallets-controller@kolabnow.com', 'simple123', true);
        });

        // Assert Receipts tab content when there's no receipts available
        $this->browse(static function (Browser $browser) {
            $browser->on(new Dashboard())
                ->click('@links .link-wallet')
                ->on(new WalletPage())
                ->assertSeeIn('#wallet .card-title', 'Account balance 0,00 CHF')
                ->assertSeeIn('#wallet .card-title .text-success', '0,00 CHF')
                ->assertSeeIn('#wallet .card-text', 'You are in your free trial period.')
                ->assertSeeIn('@nav #tab-receipts', 'Receipts')
                ->with('@receipts-tab', static function (Browser $browser) {
                    $browser->waitUntilMissing('.app-loader')
                        ->assertSeeIn('td', 'There are no receipts for payments')
                        ->assertMissing('button');
                });
        });

        // Create some sample payments
        $receipts = [];
        $date = Carbon::create((int) date('Y') - 1, 3, 30);
        $payment = Payment::create([
            'id' => 'AAA1',
            'status' => Payment::STATUS_PAID,
            'type' => Payment::TYPE_ONEOFF,
            'description' => 'Paid in March',
            'wallet_id' => $wallet->id,
            'provider' => 'stripe',
            'amount' => 1111,
            'credit_amount' => 1111,
            'currency_amount' => 1111,
            'currency' => 'CHF',
        ]);
        $payment->updated_at = $date;
        $payment->save();
        $receipts[] = $date->format('Y-m');

        $date = Carbon::create((int) date('Y') - 1, 4, 30);
        $payment = Payment::create([
            'id' => 'AAA2',
            'status' => Payment::STATUS_PAID,
            'type' => Payment::TYPE_ONEOFF,
            'description' => 'Paid in April',
            'wallet_id' => $wallet->id,
            'provider' => 'stripe',
            'amount' => 1111,
            'credit_amount' => 1111,
            'currency_amount' => 1111,
            'currency' => 'CHF',
        ]);
        $payment->updated_at = $date;
        $payment->save();
        $receipts[] = $date->format('Y-m');

        // Assert Receipts tab with receipts available
        $this->browse(function (Browser $browser) use ($receipts) {
            $browser->refresh()
                ->on(new WalletPage())
                ->assertSeeIn('@nav #tab-receipts', 'Receipts')
                ->with('@receipts-tab', function (Browser $browser) use ($receipts) {
                    $browser->waitUntilMissing('.app-loader')
                        ->assertElementsCount('table tbody tr', 2)
                        ->assertMissing('.more-loader button', 'Load more')
                        ->assertSeeIn('table tbody tr:nth-child(1) td.datetime', $receipts[1])
                        ->assertSeeIn('table tbody tr:nth-child(2) td.datetime', $receipts[0]);

                    // Download a receipt file
                    $browser->click('table tbody tr:nth-child(2) td.buttons button')
                        ->waitUntilMissing('.app-loader');

                    $content = $browser->readDownloadedFile($filename = "Kolab Receipt for {$receipts[0]}.pdf");
                    $this->assertStringStartsWith("%PDF-1.", $content);

                    $browser->removeDownloadedFile($filename);
                });
        });
    }

    /**
     * Test Referral Programs tab
     */
    public function testReferralPrograms(): void
    {
        $user = $this->getTestUser('wallets-controller@kolabnow.com', ['password' => 'simple123']);
        $wallet = $user->wallets()->first();

        // Log out and log-in the test user
        $this->browse(static function (Browser $browser) {
            $browser->visit('/logout')
                ->waitForLocation('/login')
                ->on(new Home())
                ->submitLogon('wallets-controller@kolabnow.com', 'simple123', true);
        });

        // Assert Referral Programs tab content when there's no programs available
        $this->browse(static function (Browser $browser) {
            $browser->on(new Dashboard())
                ->click('@links .link-wallet')
                ->on(new WalletPage())
                ->assertSeeIn('@nav #tab-refprograms', 'Referral Programs')
                ->click('@nav #tab-refprograms')
                ->whenAvailable('@refprograms-tab', static function (Browser $browser) {
                    $browser->waitUntilMissing('.app-loader')
                        ->assertSeeIn('div', 'There are no active referral programs at this moment.')
                        ->assertMissing('ul');
                });
        });

        // Create sample program
        $program = ReferralProgram::create([
            'name' => "Test Referral",
            'description' => "Test Referral Description",
            'active' => true,
        ]);

        // Assert Referral Programs tab with programs available
        $this->browse(static function (Browser $browser) use ($program, $user) {
            $browser->refresh()
                ->on(new WalletPage())
                ->click('@nav #tab-refprograms')
                ->whenAvailable('@refprograms-tab ul', static function (Browser $browser) use ($program, $user) {
                    $code = $program->codes()->where('user_id', $user->id)->first();

                    $browser->assertElementsCount('li', 1)
                        ->assertVisible('li:nth-child(1) img')
                        ->assertSeeIn('li:nth-child(1) p.name', $program->name)
                        ->assertSeeIn('li:nth-child(1) p.description', $program->description)
                        ->assertSeeIn('li:nth-child(1) p.info', 'Signup URL: ' . $code->signupUrl())
                        ->assertSeeIn('li:nth-child(1) p.info', 'Accounts: 0');
                });
        });
    }

    /**
     * Test History tab
     */
    public function testHistory(): void
    {
        $user = $this->getTestUser('wallets-controller@kolabnow.com', ['password' => 'simple123']);

        // Log out John and log in the test user
        $this->browse(static function (Browser $browser) {
            $browser->visit('/logout')
                ->waitForLocation('/login')
                ->on(new Home())
                ->submitLogon('wallets-controller@kolabnow.com', 'simple123', true);
        });

        $package_kolab = Package::where('title', 'kolab')->first();
        $user->assignPackage($package_kolab);
        $wallet = $user->wallets()->first();

        // Create some sample transactions
        $transactions = $this->createTestTransactions($wallet);
        $transactions = array_reverse($transactions);
        $pages = array_chunk($transactions, 10 /* page size */);

        $this->browse(static function (Browser $browser) use ($pages) {
            $browser->on(new Dashboard())
                ->click('@links .link-wallet')
                ->on(new WalletPage())
                ->assertSeeIn('@nav #tab-history', 'History')
                ->click('@nav #tab-history')
                ->with('@history-tab', static function (Browser $browser) use ($pages) {
                    $browser->waitUntilMissing('.app-loader')
                        ->assertElementsCount('table tbody tr', 10)
                        ->assertMissing('table td.email')
                        ->assertSeeIn('.more-loader button', 'Load more');

                    foreach ($pages[0] as $idx => $transaction) {
                        $selector = 'table tbody tr:nth-child(' . ($idx + 1) . ')';
                        $priceStyle = $transaction->type == Transaction::WALLET_AWARD ? 'text-success' : 'text-danger';
                        $browser->assertSeeIn("{$selector} td.description", $transaction->shortDescription())
                            ->assertMissing("{$selector} td.selection button")
                            ->assertVisible("{$selector} td.price.{$priceStyle}");
                        // TODO: Test more transaction details
                    }

                    // Load the next page
                    $browser->click('.more-loader button')
                        ->waitUntilMissing('.app-loader')
                        ->assertElementsCount('table tbody tr', 12)
                        ->assertMissing('.more-loader button');

                    $debitEntry = null;
                    foreach ($pages[1] as $idx => $transaction) {
                        $selector = 'table tbody tr:nth-child(' . ($idx + 1 + 10) . ')';
                        $priceStyle = $transaction->type == Transaction::WALLET_CREDIT ? 'text-success' : 'text-danger';
                        $browser->assertSeeIn("{$selector} td.description", $transaction->shortDescription());

                        if ($transaction->type == Transaction::WALLET_DEBIT) {
                            $debitEntry = $selector;
                        } else {
                            $browser->assertMissing("{$selector} td.selection button");
                        }
                    }

                    // Load sub-transactions
                    $browser->scrollTo("{$debitEntry} td.selection button")->pause(500)
                        ->click("{$debitEntry} td.selection button")
                        ->waitUntilMissing('.app-loader')
                        ->assertElementsCount("{$debitEntry} td.description ul li", 2)
                        ->assertMissing("{$debitEntry} td.selection button");
                });
        });
    }

    /**
     * Test that non-controller user has no access to wallet
     */
    public function testAccessDenied(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->visit('/logout')
                ->on(new Home())
                ->submitLogon('jack@kolab.org', 'simple123', true)
                ->on(new Dashboard())
                ->assertMissing('@links .link-wallet')
                ->visit('/wallet')
                ->assertErrorPage(403, "Only account owners can access a wallet.");
        });
    }
}
