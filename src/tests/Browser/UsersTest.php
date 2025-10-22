<?php

namespace Tests\Browser;

use App\Delegation;
use App\Discount;
use App\Entitlement;
use App\Package;
use App\Sku;
use App\User;
use App\UserAlias;
use App\VerificationCode;
use Tests\Browser;
use Tests\Browser\Components\CountrySelect;
use Tests\Browser\Components\Dialog;
use Tests\Browser\Components\ListInput;
use Tests\Browser\Components\QuotaInput;
use Tests\Browser\Components\Toast;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\Home;
use Tests\Browser\Pages\UserInfo;
use Tests\Browser\Pages\UserList;
use Tests\Browser\Pages\Wallet as WalletPage;
use Tests\TestCaseDusk;

class UsersTest extends TestCaseDusk
{
    private $profile = [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'organization' => 'Kolab Developers',
        'limit_geo' => null,
        'currency' => 'USD',
        'country' => 'US',
        'billing_address' => "601 13th Street NW\nSuite 900 South\nWashington, DC 20005",
        'external_email' => 'john.doe.external@gmail.com',
        'phone' => '+1 509-248-1111',
        'itip_config' => null,
        'externalsender_config' => null,
        'greylist_policy' => null,
        'password_expired' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('julia.roberts@kolab.org');

        $john = User::where('email', 'john@kolab.org')->first();
        $john->setSettings($this->profile);
        UserAlias::where('user_id', $john->id)
            ->where('alias', 'john.test@kolab.org')->delete();

        $activesync_sku = Sku::withEnvTenantContext()->where('title', 'activesync')->first();
        $storage_sku = Sku::withEnvTenantContext()->where('title', 'storage')->first();

        Entitlement::where('entitleable_id', $john->id)->where('sku_id', $activesync_sku->id)->delete();
        Entitlement::where('cost', '>=', 5000)->delete();
        Entitlement::where('cost', '=', 25)->where('sku_id', $storage_sku->id)->delete();

        $wallet = $john->wallets()->first();
        $wallet->discount()->dissociate();
        $wallet->currency = 'CHF';
        $wallet->save();

        $this->clearBetaEntitlements();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('julia.roberts@kolab.org');

        $john = User::where('email', 'john@kolab.org')->first();
        $john->setSettings($this->profile);
        $john->aliases()->where('alias', 'john.test@kolab.org')->delete();
        $john->delegators()->each(static function ($user) {
            $user->delegation->delete();
        });

        $activesync_sku = Sku::withEnvTenantContext()->where('title', 'activesync')->first();
        $storage_sku = Sku::withEnvTenantContext()->where('title', 'storage')->first();

        Entitlement::where('entitleable_id', $john->id)->where('sku_id', $activesync_sku->id)->delete();
        Entitlement::where('cost', '>=', 5000)->delete();
        Entitlement::where('cost', '=', 25)->where('sku_id', $storage_sku->id)->delete();

        $wallet = $john->wallets()->first();
        $wallet->discount()->dissociate();
        $wallet->currency = 'CHF';
        $wallet->save();

        $this->clearBetaEntitlements();

        parent::tearDown();
    }

    /**
     * Test user page - General tab
     */
    public function testUserGeneralTab(): void
    {
        $this->browse(function (Browser $browser) {
            $john = $this->getTestUser('john@kolab.org');
            $jack = $this->getTestUser('jack@kolab.org');
            $john->verificationCodes()->delete();
            $jack->verificationCodes()->delete();
            $john->setSetting('password_policy', 'min:10,upper,digit');

            // Test that the page requires authentication
            $browser->visit('/user/' . $john->id)
                ->on(new Home())
                ->submitLogon('john@kolab.org', 'simple123', false)
                ->on(new UserInfo())
                ->assertSeeIn('#user-info .card-title', 'User account')
                ->assertSeeIn('@nav #tab-general', 'General')
                ->with('@general', static function (Browser $browser) {
                    // Assert the General tab content
                    $browser->assertSeeIn('div.row:nth-child(1) label', 'Status')
                        ->assertSeeIn('div.row:nth-child(1) #status', 'Active')
                        ->assertSeeIn('div.row:nth-child(2) label', 'Email')
                        ->assertValue('div.row:nth-child(2) input[type=text]', 'john@kolab.org')
                        ->assertDisabled('div.row:nth-child(2) input[type=text]')
                        ->assertSeeIn('div.row:nth-child(3) label', 'Email Aliases')
                        ->assertVisible('div.row:nth-child(3) .list-input')
                        ->with(new ListInput('#aliases'), static function (Browser $browser) {
                            $browser->assertListInputValue(['john.doe@kolab.org'])
                                ->assertValue('@input', '');
                        })
                        ->assertSeeIn('div.row:nth-child(4) label', 'Password')
                        ->assertMissing('div.row:nth-child(4) label > button') // expired password tooltip button
                        ->assertValue('div.row:nth-child(4) input#password', '')
                        ->assertValue('div.row:nth-child(4) input#password_confirmation', '')
                        ->assertAttribute('#password', 'placeholder', 'Password')
                        ->assertAttribute('#password_confirmation', 'placeholder', 'Confirm Password')
                        ->assertMissing('div.row:nth-child(4) .btn-group')
                        ->assertMissing('div.row:nth-child(4) #password-link');

                    // Test error handling (password)
                    $browser->type('#password', 'aaaaaA')
                        ->vueClear('#password_confirmation')
                        ->whenAvailable('#password_policy', static function (Browser $browser) {
                            $browser->assertElementsCount('li', 3)
                                ->assertMissing('li:nth-child(1) svg.text-success')
                                ->assertSeeIn('li:nth-child(1) small', "Minimum password length: 10 characters")
                                ->waitFor('li:nth-child(2) svg.text-success')
                                ->assertSeeIn('li:nth-child(2) small', "Password contains an upper-case character")
                                ->assertMissing('li:nth-child(3) svg.text-success')
                                ->assertSeeIn('li:nth-child(3) small', "Password contains a digit");
                        })
                        ->click('button[type=submit]')
                        ->waitFor('#password_confirmation + .invalid-feedback')
                        ->assertSeeIn(
                            '#password_confirmation + .invalid-feedback',
                            'The password confirmation does not match.'
                        )
                        ->assertFocused('#password')
                        ->assertToast(Toast::TYPE_ERROR, 'Form validation error');

                    // TODO: Test password change

                    // Test form error handling (aliases)
                    $browser->vueClear('#password')
                        ->vueClear('#password_confirmation')
                        ->with(new ListInput('#aliases'), static function (Browser $browser) {
                            $browser->addListEntry('invalid address');
                        })
                        ->scrollTo('button[type=submit]')->pause(500)
                        ->click('button[type=submit]')
                        ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                        ->with(new ListInput('#aliases'), static function (Browser $browser) {
                            $browser->assertFormError(2, 'The specified alias is invalid.', false)
                                // Test adding aliases
                                ->removeListEntry(2)
                                ->addListEntry('john.test@kolab.org');
                        })
                        ->click('button[type=submit]')
                        ->assertToast(Toast::TYPE_SUCCESS, 'User data updated successfully.');
                })
                ->on(new UserList())
                ->click('@table tr:nth-child(3) a')
                ->on(new UserInfo());

            $alias = $john->aliases()->where('alias', 'john.test@kolab.org')->first();
            $this->assertTrue(!empty($alias));

            // Test expired password indication
            $john->setSetting('password_expired', '2020-01-01 10:10:10');

            $browser->refresh()
                ->on(new UserInfo())
                ->with('@general', static function (Browser $browser) {
                    $browser->assertTip(
                        'div.row:nth-child(4) label > button',
                        'Password expired on 2020-01-01 10:10:10'
                    );
                });

            // Test subscriptions
            $browser->with('@general', static function (Browser $browser) {
                $browser->assertSeeIn('div.row:nth-child(5) label', 'Subscriptions')
                    ->assertVisible('@skus.row:nth-child(5)')
                    ->with('@skus', static function ($browser) {
                        $browser->assertElementsCount('tbody tr', 5)
                            // Mailbox SKU
                            ->assertSeeIn('tbody tr:nth-child(1) td.name', 'User Mailbox')
                            ->assertSeeIn('tbody tr:nth-child(1) td.price', '5,00 CHF/month')
                            ->assertChecked('tbody tr:nth-child(1) td.selection input')
                            ->assertDisabled('tbody tr:nth-child(1) td.selection input')
                            ->assertTip(
                                'tbody tr:nth-child(1) td.buttons button',
                                'Just a mailbox'
                            )
                            // Storage SKU
                            ->assertSeeIn('tbody tr:nth-child(2) td.name', 'Storage Quota')
                            ->assertSeeIn('tr:nth-child(2) td.price', '0,00 CHF/month')
                            ->assertChecked('tbody tr:nth-child(2) td.selection input')
                            ->assertDisabled('tbody tr:nth-child(2) td.selection input')
                            ->assertTip(
                                'tbody tr:nth-child(2) td.buttons button',
                                'Some wiggle room'
                            )
                            ->with(new QuotaInput('tbody tr:nth-child(2) .range-input'), static function ($browser) {
                                $browser->assertQuotaValue(5)->setQuotaValue(6);
                            })
                            ->assertSeeIn('tr:nth-child(2) td.price', '0,25 CHF/month')
                            // Groupware SKU
                            ->assertSeeIn('tbody tr:nth-child(3) td.name', 'Groupware Features')
                            ->assertSeeIn('tbody tr:nth-child(3) td.price', '4,90 CHF/month')
                            ->assertChecked('tbody tr:nth-child(3) td.selection input')
                            ->assertEnabled('tbody tr:nth-child(3) td.selection input')
                            ->assertTip(
                                'tbody tr:nth-child(3) td.buttons button',
                                'Groupware functions like Calendar, Tasks, Notes, etc.'
                            )
                            // ActiveSync SKU
                            ->assertSeeIn('tbody tr:nth-child(4) td.name', 'Activesync')
                            ->assertSeeIn('tbody tr:nth-child(4) td.price', '0,00 CHF/month')
                            ->assertNotChecked('tbody tr:nth-child(4) td.selection input')
                            ->assertEnabled('tbody tr:nth-child(4) td.selection input')
                            ->assertTip(
                                'tbody tr:nth-child(4) td.buttons button',
                                'Mobile synchronization'
                            )
                            // 2FA SKU
                            ->assertSeeIn('tbody tr:nth-child(5) td.name', '2-Factor Authentication')
                            ->assertSeeIn('tbody tr:nth-child(5) td.price', '0,00 CHF/month')
                            ->assertNotChecked('tbody tr:nth-child(5) td.selection input')
                            ->assertEnabled('tbody tr:nth-child(5) td.selection input')
                            ->assertTip(
                                'tbody tr:nth-child(5) td.buttons button',
                                'Two factor authentication for webmail and administration panel'
                            )
                            ->click('tbody tr:nth-child(4) td.selection input');
                    })
                    ->assertMissing('@skus table + .hint')
                    ->click('button[type=submit]')
                    ->assertToast(Toast::TYPE_SUCCESS, 'User data updated successfully.');
            })
                ->on(new UserList())
                ->click('@table tr:nth-child(3) a')
                ->on(new UserInfo());

            $expected = ['activesync', 'groupware', 'mailbox',
                'storage', 'storage', 'storage', 'storage', 'storage', 'storage'];
            $this->assertEntitlements($john->fresh(), $expected);

            // Test subscriptions interaction
            $browser->with('@general', static function (Browser $browser) {
                $browser->with('@skus', static function ($browser) {
                    // Uncheck 'groupware', expect activesync unchecked
                    $browser->click('#sku-input-groupware')
                        ->assertNotChecked('#sku-input-groupware')
                        ->assertNotChecked('#sku-input-activesync')
                        ->assertEnabled('#sku-input-activesync')
                        ->assertNotReadonly('#sku-input-activesync')
                        // Check 'activesync', expect an alert
                        ->click('#sku-input-activesync')
                        ->assertDialogOpened('Activesync requires Groupware Features.')
                        ->acceptDialog()
                        ->assertNotChecked('#sku-input-activesync')
                        // Check '2FA', expect 'activesync' unchecked and readonly
                        ->click('#sku-input-2fa')
                        ->assertChecked('#sku-input-2fa')
                        ->assertNotChecked('#sku-input-activesync')
                        ->assertReadonly('#sku-input-activesync')
                        // Uncheck '2FA'
                        ->click('#sku-input-2fa')
                        ->assertNotChecked('#sku-input-2fa')
                        ->assertNotReadonly('#sku-input-activesync');
                });
            });

            // Test password reset link delete and create
            $code = new VerificationCode(['mode' => VerificationCode::MODE_PASSWORD]);
            $jack->verificationCodes()->save($code);

            $browser->visit('/user/' . $jack->id)
                ->on(new UserInfo())
                ->with('@general', function (Browser $browser) use ($jack, $john, $code) {
                    // Test displaying an existing password reset link
                    $link = Browser::$baseUrl . '/password-reset/' . $code->short_code . '-' . $code->code;
                    $browser->assertSeeIn('div.row:nth-child(4) label', 'Password')
                        ->assertMissing('#password')
                        ->assertMissing('#password_confirmation')
                        ->assertMissing('#pass-mode-link:checked')
                        ->assertMissing('#pass-mode-input:checked')
                        ->assertSeeIn('#password-link code', $link)
                        ->assertVisible('#password-link button.text-danger')
                        ->assertVisible('#password-link button:not(.text-danger)')
                        ->assertAttribute('#password-link button:not(.text-danger)', 'title', 'Copy')
                        ->assertAttribute('#password-link button.text-danger', 'title', 'Delete')
                        ->assertMissing('#password-link div.form-text');

                    // Test deleting an existing password reset link
                    $browser->click('#password-link button.text-danger')
                        ->assertToast(Toast::TYPE_SUCCESS, 'Password reset code deleted successfully.')
                        ->assertMissing('#password-link')
                        ->assertMissing('#pass-mode-link:checked')
                        ->assertMissing('#pass-mode-input:checked')
                        ->assertMissing('#password');

                    $this->assertSame(0, $jack->verificationCodes()->count());

                    // Test creating a password reset link
                    $link = preg_replace('|/[a-z0-9A-Z-]+$|', '', $link) . '/';
                    $browser->click('#pass-mode-link + label')
                        ->assertMissing('#password')
                        ->assertMissing('#password_confirmation')
                        ->waitFor('#password-link code')
                        ->assertSeeIn('#password-link code', $link)
                        ->assertSeeIn('#password-link div.form-text', "Press Submit to activate the link")
                        ->pause(100);

                    // Test copy to clipboard
                    /* TODO: Figure out how to give permission to do this operation
                    $code = $john->verificationCodes()->first();
                    $link .= $code->short_code . '-' . $code->code;

                    $browser->assertMissing('#password-link button.text-danger')
                        ->click('#password-link button:not(.text-danger)')
                        ->keys('#organization', ['{left_control}', 'v'])
                        ->assertAttribute('#organization', 'value', $link)
                        ->vueClear('#organization');
                    */

                    // Finally submit the form
                    $browser->scrollTo('button[type=submit]')->pause(500)
                        ->click('button[type=submit]')
                        ->assertToast(Toast::TYPE_SUCCESS, 'User data updated successfully.');

                    $this->assertSame(1, $jack->verificationCodes()->where('active', true)->count());
                    $this->assertSame(0, $john->verificationCodes()->count());
                });
        });
    }

    /**
     * Test user page - General tab
     *
     * @depends testUserGeneralTab
     */
    public function testUserPersonalTab(): void
    {
        $this->browse(function (Browser $browser) {
            $john = $this->getTestUser('john@kolab.org');
            $jack = $this->getTestUser('jack@kolab.org');
            $jack->setSetting('organization', null);

            // Test the account controller
            $browser->visit('/user/' . $john->id)
                ->on(new UserInfo())
                ->assertSeeIn('@nav #tab-personal', 'Personal information')
                ->click('#tab-personal')
                ->with('@personal', function (Browser $browser) {
                    $browser->assertSeeIn('div.row:nth-child(1) label', 'First Name')
                        ->assertValue('div.row:nth-child(1) input[type=text]', $this->profile['first_name'])
                        ->assertSeeIn('div.row:nth-child(2) label', 'Last Name')
                        ->assertValue('div.row:nth-child(2) input[type=text]', $this->profile['last_name'])
                        ->assertSeeIn('div.row:nth-child(3) label', 'Organization')
                        ->assertValue('div.row:nth-child(3) input[type=text]', $this->profile['organization'])
                        ->assertSeeIn('div.row:nth-child(4) label', 'Phone')
                        ->assertValue('div.row:nth-child(4) input[type=text]', $this->profile['phone'])
                        ->assertSeeIn('div.row:nth-child(5) label', 'External Email')
                        ->assertValue('div.row:nth-child(5) input[type=text]', $this->profile['external_email'])
                        ->assertSeeIn('div.row:nth-child(6) label', 'Address')
                        ->assertValue('div.row:nth-child(6) textarea', $this->profile['billing_address'])
                        ->assertSeeIn('div.row:nth-child(7) label', 'Country')
                        ->assertValue('div.row:nth-child(7) select', $this->profile['country'])
                        // Set some fields and submit
                        ->type('#first_name', 'Arnie')
                        ->vueClear('#last_name')
                        ->click('button[type=submit]');
                })
                ->assertToast(Toast::TYPE_SUCCESS, 'User data updated successfully.')
                ->on(new UserList());

            $this->assertSame('Arnie', $john->getSetting('first_name'));
            $this->assertNull($john->getSetting('last_name'));

            // Test the non-controller user
            $browser->visit('/user/' . $jack->id)
                ->on(new UserInfo())
                ->click('#tab-personal')
                ->with('@personal', static function (Browser $browser) {
                    $browser->assertSeeIn('div.row:nth-child(1) label', 'First Name')
                        ->assertValue('div.row:nth-child(1) input[type=text]', 'Jack')
                        ->assertSeeIn('div.row:nth-child(2) label', 'Last Name')
                        ->assertValue('div.row:nth-child(2) input[type=text]', 'Daniels')
                        ->assertSeeIn('div.row:nth-child(3) label', 'Organization')
                        ->assertValue('div.row:nth-child(3) input[type=text]', '')
                        // Set some fields and submit
                        ->type('#organization', 'Test')
                        ->click('button[type=submit]');
                })
                ->assertToast(Toast::TYPE_SUCCESS, 'User data updated successfully.')
                ->on(new UserList());

            $this->assertSame('Test', $jack->getSetting('organization'));
        });
    }

    /**
     * Test user page - Settings tab
     *
     * @depends testUserPersonalTab
     */
    public function testUserSettingsTab(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $john->setSetting('greylist_enabled', null);
        $john->setSetting('greylist_policy', null);
        $john->setSetting('guam_enabled', null);
        $john->setSetting('limit_geo', null);
        $john->setSetting('externalsender_config', 'false');

        // Mail delivery section
        $this->browse(static function (Browser $browser) use ($john) {
            $browser->visit('/user/' . $john->id)
                ->on(new UserInfo())
                ->assertSeeIn('@nav #tab-settings', 'Settings')
                ->click('@nav #tab-settings')
                ->assertMissing('@setting-options-head') // all main options are hidden
                ->assertMissing('@setting-options')
                ->assertSeeIn('@setting-maildelivery-head', 'Mail delivery')
                ->with('@setting-maildelivery', static function (Browser $browser) {
                    $browser->assertSeeIn('div.row:nth-child(1) label', 'Greylisting')
                        ->assertSelectHasOptions('div.row:nth-child(1) select', ['', 'true', 'false'])
                        ->assertSelected('div.row:nth-child(1) select', '')
                        ->assertText('div.row:nth-child(1) select > option:nth-child(1)', 'default (enabled)')
                        ->select('div.row:nth-child(1) select', 'false')
                        ->assertSeeIn('div.row:nth-child(2) label', 'Calendar invitations')
                        ->assertSelectHasOptions('div.row:nth-child(2) select', ['', 'true', 'false'])
                        ->assertSelected('div.row:nth-child(2) select', '')
                        ->assertText('div.row:nth-child(2) select > option:nth-child(1)', 'default (disabled)')
                        ->select('div.row:nth-child(2) select', 'true')
                        ->assertSeeIn('div.row:nth-child(3) label', 'External sender warning')
                        ->assertSelectHasOptions('div.row:nth-child(3) select', ['', 'true', 'false'])
                        ->assertSelected('div.row:nth-child(3) select', 'false')
                        ->assertText('div.row:nth-child(3) select > option:nth-child(1)', 'default (disabled)')
                        ->select('div.row:nth-child(3) select', '')
                        ->click('button[type=submit]')
                        ->assertToast(Toast::TYPE_SUCCESS, 'User settings updated successfully.');
                });
        });

        $this->assertSame('false', $john->getSetting('greylist_enabled'));
        $this->assertSame('true', $john->getSetting('itip_config'));
        $this->assertNull($john->getSetting('externalsender_config'));

        // Main options section
        $this->addBetaEntitlement($john);
        $this->browse(function (Browser $browser) use ($john) {
            $browser->refresh()
                ->on(new UserInfo())
                ->click('@nav #tab-settings')
                ->assertSeeIn('@setting-options-head', 'Main options')
                ->with('@setting-options', function (Browser $browser) use ($john) {
                    $browser->assertSeeIn('div.row:nth-child(1) label', 'IMAP proxy')
                        ->assertNotChecked('div.row:nth-child(1) input')
                        ->assertSeeIn('div.row:nth-child(2) label', 'Geo-lockin')
                        ->with(new CountrySelect('#limit_geo'), static function ($browser) {
                            $browser->assertCountries([])
                                ->setCountries(['CH', 'PL'])
                                ->assertCountries(['CH', 'PL']);
                        })
                        ->click('div.row:nth-child(1) input')
                        ->click('button[type=submit]')
                        ->assertToast(Toast::TYPE_SUCCESS, 'User settings updated successfully.');

                    $this->assertSame('["CH","PL"]', $john->getSetting('limit_geo'));
                    $this->assertSame('true', $john->getSetting('guam_enabled'));

                    $browser
                        ->with(new CountrySelect('#limit_geo'), static function ($browser) {
                            $browser->setCountries([])
                                ->assertCountries([]);
                        })
                        ->click('div.row:nth-child(1) input')
                        ->click('button[type=submit]')
                        ->assertToast(Toast::TYPE_SUCCESS, 'User settings updated successfully.');

                    $this->assertNull($john->getSetting('limit_geo'));
                    $this->assertNull($john->getSetting('guam_enabled'));
                });
        });
    }

    /**
     * Test user adding page
     */
    public function testNewUser(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $john->setSetting('password_policy', null);

        $this->browse(function (Browser $browser) {
            $browser->visit(new UserList())
                ->assertSeeIn('button.user-new', 'Create user')
                ->click('button.user-new')
                ->on(new UserInfo())
                ->assertSeeIn('#user-info .card-title', 'New user account')
                ->assertMissing('@nav #tab-settings')
                ->assertMissing('@nav #tab-personal')
                ->with('@general', static function (Browser $browser) {
                    // Assert form content
                    $browser->assertFocused('div.row:nth-child(1) input')
                        ->assertSeeIn('div.row:nth-child(1) label', 'First Name')
                        ->assertValue('div.row:nth-child(1) input[type=text]', '')
                        ->assertSeeIn('div.row:nth-child(2) label', 'Last Name')
                        ->assertValue('div.row:nth-child(2) input[type=text]', '')
                        ->assertSeeIn('div.row:nth-child(3) label', 'Organization')
                        ->assertValue('div.row:nth-child(3) input[type=text]', '')
                        ->assertSeeIn('div.row:nth-child(4) label', 'Email')
                        ->assertValue('div.row:nth-child(4) input[type=text]', '')
                        ->assertEnabled('div.row:nth-child(4) input[type=text]')
                        ->assertSeeIn('div.row:nth-child(5) label', 'Email Aliases')
                        ->assertVisible('div.row:nth-child(5) .list-input')
                        ->with(new ListInput('#aliases'), static function (Browser $browser) {
                            $browser->assertListInputValue([])
                                ->assertValue('@input', '');
                        })
                        ->assertSeeIn('div.row:nth-child(6) label', 'Password')
                        ->assertValue('div.row:nth-child(6) input#password', '')
                        ->assertValue('div.row:nth-child(6) input#password_confirmation', '')
                        ->assertAttribute('#password', 'placeholder', 'Password')
                        ->assertAttribute('#password_confirmation', 'placeholder', 'Confirm Password')
                        ->assertSeeIn('div.row:nth-child(6) .btn-group input:first-child + label', 'Enter password')
                        ->assertSeeIn('div.row:nth-child(6) .btn-group input:not(:first-child) + label', 'Set via link')
                        ->assertChecked('div.row:nth-child(6) .btn-group input:first-child')
                        ->assertMissing('div.row:nth-child(6) #password-link')
                        ->assertSeeIn('div.row:nth-child(7) label', 'Package')
                        // assert packages list widget, select "Lite Account"
                        ->with('@packages', static function ($browser) {
                            $browser->assertElementsCount('tbody tr', 2)
                                ->assertSeeIn('tbody tr:nth-child(1)', 'Groupware Account')
                                ->assertSeeIn('tbody tr:nth-child(2)', 'Lite Account')
                                ->assertSeeIn('tbody tr:nth-child(1) .price', '9,90 CHF/month')
                                ->assertSeeIn('tbody tr:nth-child(2) .price', '5,00 CHF/month')
                                ->assertChecked('tbody tr:nth-child(1) input')
                                ->click('tbody tr:nth-child(2) input')
                                ->assertNotChecked('tbody tr:nth-child(1) input')
                                ->assertChecked('tbody tr:nth-child(2) input');
                        })
                        ->assertMissing('@packages table + .hint')
                        ->assertSeeIn('button[type=submit]', 'Submit');

                    // Test browser-side required fields and error handling
                    $browser->scrollTo('button[type=submit]')->pause(500)
                        ->click('button[type=submit]')
                        ->assertFocused('#email')
                        ->type('#email', 'invalid email')
                        ->type('#password', 'simple123')
                        ->type('#password_confirmation', 'simple')
                        ->click('button[type=submit]')
                        ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                        ->assertSeeIn('#email + .invalid-feedback', 'The specified email is invalid.')
                        ->assertSeeIn(
                            '#password_confirmation + .invalid-feedback',
                            'The password confirmation does not match.'
                        );
                });

            // Test form error handling (aliases)
            $browser->with('@general', static function (Browser $browser) {
                $browser->type('#email', 'julia.roberts@kolab.org')
                    ->type('#password_confirmation', 'simple123')
                    ->with(new ListInput('#aliases'), static function (Browser $browser) {
                        $browser->addListEntry('invalid address');
                    })
                    ->click('button[type=submit]')
                    ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                    ->with(new ListInput('#aliases'), static function (Browser $browser) {
                        $browser->assertFormError(1, 'The specified alias is invalid.', false);
                    });
            });

            // Successful account creation
            $browser->with('@general', static function (Browser $browser) {
                $browser->type('#first_name', 'Julia')
                    ->type('#last_name', 'Roberts')
                    ->type('#organization', 'Test Org')
                    ->with(new ListInput('#aliases'), static function (Browser $browser) {
                        $browser->removeListEntry(1)
                            ->addListEntry('julia.roberts2@kolab.org');
                    })
                    ->click('button[type=submit]');
            })
                ->assertToast(Toast::TYPE_SUCCESS, 'User created successfully.')
                // check redirection to users list
                ->on(new UserList())
                ->whenAvailable('@table', static function (Browser $browser) {
                    $browser->assertElementsCount('tbody tr', 5)
                        ->assertSeeIn('tbody tr:nth-child(4) a', 'julia.roberts@kolab.org');
                });

            $julia = User::where('email', 'julia.roberts@kolab.org')->first();
            $alias = UserAlias::where('user_id', $julia->id)->where('alias', 'julia.roberts2@kolab.org')->first();

            $this->assertTrue(!empty($alias));
            $this->assertEntitlements($julia, ['mailbox', 'storage', 'storage', 'storage', 'storage', 'storage']);
            $this->assertSame('Julia', $julia->getSetting('first_name'));
            $this->assertSame('Roberts', $julia->getSetting('last_name'));
            $this->assertSame('Test Org', $julia->getSetting('organization'));

            // Some additional tests for the list input widget
            $browser->click('@table tbody tr:nth-child(4) a')
                ->on(new UserInfo())
                ->with(new ListInput('#aliases'), static function (Browser $browser) {
                    $browser->assertListInputValue(['julia.roberts2@kolab.org'])
                        ->addListEntry('invalid address')
                        ->type('.input-group:nth-child(2) input', '@kolab.org')
                        ->keys('.input-group:nth-child(2) input', '{enter}');
                })
                // TODO: Investigate why this click does not work, for now we
                // submit the form with Enter key above
                // ->click('@general button[type=submit]')
                ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                ->with(new ListInput('#aliases'), static function (Browser $browser) {
                    $browser->assertVisible('.input-group:nth-child(2) input.is-invalid')
                        ->assertVisible('.input-group:nth-child(3) input.is-invalid')
                        ->type('.input-group:nth-child(2) input', 'julia.roberts3@kolab.org')
                        ->type('.input-group:nth-child(3) input', 'julia.roberts4@kolab.org')
                        ->keys('.input-group:nth-child(3) input', '{enter}');
                })
                // TODO: Investigate why this click does not work, for now we
                // submit the form with Enter key above
                // ->click('@general button[type=submit]')
                ->assertToast(Toast::TYPE_SUCCESS, 'User data updated successfully.');

            $julia = User::where('email', 'julia.roberts@kolab.org')->first();
            $aliases = $julia->aliases()->orderBy('alias')->get()->pluck('alias')->all();

            $this->assertSame(['julia.roberts3@kolab.org', 'julia.roberts4@kolab.org'], $aliases);
        });
    }

    /**
     * Test user delete
     *
     * @depends testNewUser
     */
    public function testDeleteUser(): void
    {
        // First create a new user
        $john = $this->getTestUser('john@kolab.org');
        $julia = $this->getTestUser('julia.roberts@kolab.org');
        $package_kolab = Package::where('title', 'kolab')->first();
        $john->assignPackage($package_kolab, $julia);

        // Test deleting non-controller user
        $this->browse(function (Browser $browser) use ($julia) {
            $browser->visit('/user/' . $julia->id)
                ->on(new UserInfo())
                ->assertSeeIn('button.button-delete', 'Delete user')
                ->click('button.button-delete')
                ->with(new Dialog('#delete-warning'), static function (Browser $browser) {
                    $browser->assertSeeIn('@title', 'Delete julia.roberts@kolab.org')
                        ->assertFocused('@button-cancel')
                        ->assertSeeIn('@button-cancel', 'Cancel')
                        ->assertSeeIn('@button-action', 'Delete')
                        ->click('@button-cancel');
                })
                ->waitUntilMissing('#delete-warning')
                ->click('button.button-delete')
                ->with(new Dialog('#delete-warning'), static function (Browser $browser) {
                    $browser->click('@button-action');
                })
                ->waitUntilMissing('#delete-warning')
                ->assertToast(Toast::TYPE_SUCCESS, 'User deleted successfully.')
                ->on(new UserList())
                ->with('@table', static function (Browser $browser) {
                    $browser->assertElementsCount('tbody tr', 4)
                        ->assertSeeIn('tbody tr:nth-child(1) a', 'jack@kolab.org')
                        ->assertSeeIn('tbody tr:nth-child(2) a', 'joe@kolab.org')
                        ->assertSeeIn('tbody tr:nth-child(3) a', 'john@kolab.org')
                        ->assertSeeIn('tbody tr:nth-child(4) a', 'ned@kolab.org');
                });

            $julia = User::where('email', 'julia.roberts@kolab.org')->first();
            $this->assertTrue(empty($julia));
        });

        // Test that non-controller user cannot see/delete himself on the users list
        $this->browse(static function (Browser $browser) {
            $browser->visit('/logout')
                ->on(new Home())
                ->submitLogon('jack@kolab.org', 'simple123', true)
                ->visit('/users')
                ->assertErrorPage(403);
        });

        // Test that controller user (Ned) can see all the users
        $this->browse(function (Browser $browser) use ($john) {
            $browser->visit('/logout')
                ->on(new Home())
                ->submitLogon('ned@kolab.org', 'simple123', true)
                ->visit(new UserList())
                ->whenAvailable('@table', static function (Browser $browser) {
                    $browser->assertElementsCount('tbody tr', 4);
                });

            // Test that controller cannot edit/delete the account owner
            $browser->visit('/user/' . $john->id)
                ->assertErrorPage(403);

            // Test that controller cannot delete himself
            $ned = $this->getTestUser('ned@kolab.org');
            $browser->visit('/user/' . $ned->id)
                ->on(new UserInfo())
                ->assertMissing('button.button-delete');

            // Test that controller can delete any other user in controlled wallet
            $jack = $this->getTestUser('jack@kolab.org');
            $browser->visit('/user/' . $jack->id)
                ->on(new UserInfo())
                ->assertSeeIn('button.button-delete', 'Delete user');
        });

        // TODO: Test what happens with the logged in user session after he's been deleted by another user
    }

    /**
     * Test discounted sku/package prices in the UI
     */
    public function testDiscountedPrices(): void
    {
        // Add 10% discount
        $discount = Discount::where('code', 'TEST')->first();
        $john = User::where('email', 'john@kolab.org')->first();
        $wallet = $john->wallet();
        $wallet->discount()->associate($discount);
        $wallet->save();

        // SKUs on user edit page
        $this->browse(static function (Browser $browser) {
            $browser->visit('/logout')
                ->on(new Home())
                ->submitLogon('john@kolab.org', 'simple123', true)
                ->visit(new UserList())
                ->waitFor('@table tr:nth-child(2)')
                ->click('@table tr:nth-child(2) a') // joe@kolab.org
                ->on(new UserInfo())
                ->with('@general', static function (Browser $browser) {
                    $browser->whenAvailable('@skus', static function (Browser $browser) {
                        $quota_input = new QuotaInput('tbody tr:nth-child(2) .range-input');
                        $browser->waitFor('tbody tr')
                            ->assertElementsCount('tbody tr', 5)
                            // Mailbox SKU
                            ->assertSeeIn('tbody tr:nth-child(1) td.price', '4,50 CHF/month¹')
                            // Storage SKU
                            ->assertSeeIn('tr:nth-child(2) td.price', '0,00 CHF/month¹')
                            ->with($quota_input, static function (Browser $browser) {
                                $browser->setQuotaValue(100);
                            })
                            ->assertSeeIn('tr:nth-child(2) td.price', '21,37 CHF/month¹')
                            // Groupware SKU
                            ->assertSeeIn('tbody tr:nth-child(3) td.price', '4,41 CHF/month¹')
                            // ActiveSync SKU
                            ->assertSeeIn('tbody tr:nth-child(4) td.price', '0,00 CHF/month¹')
                            // 2FA SKU
                            ->assertSeeIn('tbody tr:nth-child(5) td.price', '0,00 CHF/month¹');
                    })
                        ->assertSeeIn('@skus table + .hint', '¹ applied discount: 10% - Test voucher');
                });
        });

        // Packages on new user page
        $this->browse(static function (Browser $browser) {
            $browser->visit(new UserList())
                ->click('button.user-new')
                ->on(new UserInfo())
                ->with('@general', static function (Browser $browser) {
                    $browser->whenAvailable('@packages', static function (Browser $browser) {
                        $browser->assertElementsCount('tbody tr', 2)
                            ->assertSeeIn('tbody tr:nth-child(1) .price', '8,91 CHF/month¹') // Groupware
                            ->assertSeeIn('tbody tr:nth-child(2) .price', '4,50 CHF/month¹'); // Lite
                    })
                        ->assertSeeIn('@packages table + .hint', '¹ applied discount: 10% - Test voucher');
                });
        });

        // Test using entitlement cost instead of the SKU cost
        $this->browse(static function (Browser $browser) use ($wallet) {
            $joe = User::where('email', 'joe@kolab.org')->first();
            $beta_sku = Sku::withEnvTenantContext()->where('title', 'beta')->first();
            $storage_sku = Sku::withEnvTenantContext()->where('title', 'storage')->first();

            // Add an extra storage and beta entitlement with different prices
            Entitlement::create([
                'wallet_id' => $wallet->id,
                'sku_id' => $beta_sku->id,
                'cost' => 5010,
                'entitleable_id' => $joe->id,
                'entitleable_type' => User::class,
            ]);
            Entitlement::create([
                'wallet_id' => $wallet->id,
                'sku_id' => $storage_sku->id,
                'cost' => 5000,
                'entitleable_id' => $joe->id,
                'entitleable_type' => User::class,
            ]);

            $browser->visit('/user/' . $joe->id)
                ->on(new UserInfo())
                ->with('@general', static function (Browser $browser) {
                    $browser->whenAvailable('@skus', static function (Browser $browser) {
                        $quota_input = new QuotaInput('tbody tr:nth-child(2) .range-input');
                        $browser->waitFor('tbody tr')
                            // Beta SKU
                            ->assertSeeIn('tbody tr:nth-child(6) td.price', '45,09 CHF/month¹')
                            // Storage SKU
                            ->assertSeeIn('tr:nth-child(2) td.price', '45,00 CHF/month¹')
                            ->with($quota_input, static function (Browser $browser) {
                                $browser->setQuotaValue(7);
                            })
                            ->assertSeeIn('tr:nth-child(2) td.price', '45,22 CHF/month¹')
                            ->with($quota_input, static function (Browser $browser) {
                                $browser->setQuotaValue(5);
                            })
                            ->assertSeeIn('tr:nth-child(2) td.price', '0,00 CHF/month¹');
                    })
                        ->assertSeeIn('@skus table + .hint', '¹ applied discount: 10% - Test voucher');
                });
        });
    }

    /**
     * Test controller "role"
     */
    public function testControllerRole(): void
    {
        $this->browse(function (Browser $browser) {
            $john = User::where('email', 'john@kolab.org')->first();
            $julia = $this->getTestUser('julia.roberts@kolab.org');
            $storage_sku = Sku::withEnvTenantContext()->where('title', 'storage')->first();
            $wallet = $john->wallets()->first();
            $julia->assignSku($storage_sku, 1, $wallet);

            $browser->visit('/logout')
                ->on(new Home())
                ->submitLogon('john@kolab.org', 'simple123', true)
                // Disabling wallets is required to access this feature
                ->withConfig(['app.with_wallet' => false])
                ->visit('/user/' . $julia->id)
                ->on(new UserInfo())
                ->assertSeeIn('#user_role', 'User')
                ->click('#user_role')
                ->with(new Dialog('#role-select'), static function (Browser $browser) {
                    $browser->assertSeeIn('@title', 'Select a role')
                        ->assertFocused('#user_role_select')
                        ->assertSelected('#user_role_select', 'user')
                        ->assertSelectHasOptions('#user_role_select', ['user', 'controller'])
                        ->assertVisible('span.form-text')
                        ->select('#user_role_select', 'controller')
                        ->assertSeeIn('@button-cancel', 'Cancel')
                        ->assertSeeIn('@button-action', 'Save')
                        ->click('@button-action');
                })
                ->waitUntilMissing('#role-select')
                ->assertToast(Toast::TYPE_SUCCESS, 'Account controller role set successfully.')
                ->assertSeeIn('#user_role', 'Controller');

            $this->assertTrue($wallet->fresh()->isController($julia));

            $browser->click('#user_role')
                ->with(new Dialog('#role-select'), static function (Browser $browser) {
                    $browser->assertSelected('#user_role_select', 'controller')
                        ->select('#user_role_select', 'user')
                        ->click('@button-action');
                })
                ->waitUntilMissing('#role-select')
                ->assertToast(Toast::TYPE_SUCCESS, 'Account controller role removed successfully.')
                ->assertSeeIn('#user_role', 'User');

            $this->assertFalse($wallet->fresh()->isController($julia));
        });
    }

    /**
     * Test non-default currency in the UI
     */
    public function testCurrency(): void
    {
        // Add 10% discount
        $john = User::where('email', 'john@kolab.org')->first();
        $wallet = $john->wallet();
        $wallet->balance = -1000;
        $wallet->currency = 'EUR';
        $wallet->save();

        // On Dashboard and the wallet page
        $this->browse(static function (Browser $browser) {
            $browser->visit('/logout')
                ->on(new Home())
                ->submitLogon('john@kolab.org', 'simple123', true)
                ->on(new Dashboard())
                ->assertSeeIn('@links .link-wallet .badge', '-10,00 €')
                ->click('@links .link-wallet')
                ->on(new WalletPage())
                ->assertSeeIn('#wallet .card-title', 'Account balance -10,00 €');
        });

        // SKUs on user edit page
        $this->browse(static function (Browser $browser) {
            $browser->visit(new UserList())
                ->waitFor('@table tr:nth-child(2)')
                ->click('@table tr:nth-child(2) a') // joe@kolab.org
                ->on(new UserInfo())
                ->with('@general', static function (Browser $browser) {
                    $browser->whenAvailable('@skus', static function (Browser $browser) {
                        $quota_input = new QuotaInput('tbody tr:nth-child(2) .range-input');
                        $browser->waitFor('tbody tr')
                            ->assertElementsCount('tbody tr', 5)
                            // Mailbox SKU
                            ->assertSeeIn('tbody tr:nth-child(1) td.price', '5,00 €/month')
                            // Storage SKU
                            ->assertSeeIn('tr:nth-child(2) td.price', '0,00 €/month')
                            ->with($quota_input, static function (Browser $browser) {
                                $browser->setQuotaValue(100);
                            })
                            ->assertSeeIn('tr:nth-child(2) td.price', '23,75 €/month');
                    });
                });
        });

        // Packages on new user page
        $this->browse(static function (Browser $browser) {
            $browser->visit(new UserList())
                ->click('button.user-new')
                ->on(new UserInfo())
                ->with('@general', static function (Browser $browser) {
                    $browser->whenAvailable('@packages', static function (Browser $browser) {
                        $browser->assertElementsCount('tbody tr', 2)
                            ->assertSeeIn('tbody tr:nth-child(1) .price', '9,90 €/month') // Groupware
                            ->assertSeeIn('tbody tr:nth-child(2) .price', '5,00 €/month'); // Lite
                    });
                });
        });
    }

    /**
     * Test delegation settings
     */
    public function testUserDelegation(): void
    {
        $jack = $this->getTestUser('jack@kolab.org');
        $jack->delegatees()->each(static function ($user) {
            $user->delegation->delete();
        });

        $this->browse(function (Browser $browser) use ($jack) {
            $browser->visit('/login')
                ->on(new Home())
                ->submitLogon($jack->email, 'simple123', true)
                ->on(new Dashboard())
                ->click('@links .link-settings')
                ->on(new UserInfo())
                ->click('@nav #tab-settings')
                // Note: Jack should not see Main options nor Mail delivery
                ->assertMissing('@setting-options')
                ->assertMissing('@setting-options-head')
                ->assertMissing('@setting-maildelivery')
                ->assertMissing('@setting-maildelivery-head')
                ->assertSeeIn('@setting-delegation-head', 'Delegation')
                // ->click('@settings .accordion-item:nth-child(2) .accordion-button')
                ->whenAvailable('@setting-delegation', static function (Browser $browser) {
                    $browser->assertSeeIn('table tfoot td', 'There are no delegates.')
                        ->assertMissing('table tbody tr');
                })
                ->assertSeeIn('@setting-delegation-head .buttons button', 'Add delegate')
                ->click('@setting-delegation-head .buttons button')
                ->with(new Dialog('#delegation-create'), static function (Browser $browser) {
                    $browser->assertSeeIn('@title', 'Add delegate')
                        ->assertFocused('#delegation-email')
                        ->assertValue('#delegation-email', '')
                        ->assertSelected('#delegation-mail', '')
                        ->assertSelectHasOptions('#delegation-mail', ['', 'read-only', 'read-write'])
                        ->assertSelected('#delegation-event', '')
                        ->assertSelectHasOptions('#delegation-event', ['', 'read-only', 'read-write'])
                        ->assertSelected('#delegation-task', '')
                        ->assertSelectHasOptions('#delegation-task', ['', 'read-only', 'read-write'])
                        ->assertSelected('#delegation-contact', '')
                        ->assertSelectHasOptions('#delegation-contact', ['', 'read-only', 'read-write'])
                        ->assertVisible('.row.form-text')
                        ->type('#delegation-email', 'john@kolab.org')
                        ->select('#delegation-mail', 'read-only')
                        ->select('#delegation-contact', 'read-write')
                        ->assertSeeIn('@button-cancel', 'Cancel')
                        ->assertSeeIn('@button-action', 'Save')
                        ->click('@button-action');
                })
                ->waitUntilMissing('#delegation-create')
                ->assertToast(Toast::TYPE_SUCCESS, 'Delegation created successfully.');

            // TODO: Test error handling
            // TODO: Test acting as a wallet controller

            $delegatee = $jack->delegatees()->first();
            $this->assertSame('john@kolab.org', $delegatee->email);
            $this->assertSame(['mail' => 'read-only', 'contact' => 'read-write'], $delegatee->delegation->options);

            // Remove delegation
            $browser->waitFor('@setting-delegation table tbody tr')
                ->whenAvailable('@setting-delegation', static function (Browser $browser) {
                    $browser->assertMissing('table tfoot td')
                        ->assertSeeIn('table tbody tr td:first-child', 'john@kolab.org')
                        ->click('table button.text-danger');
                })
                ->waitUntilMissing('@setting-delegation table tbody tr')
                ->assertToast(Toast::TYPE_SUCCESS, 'Delegation deleted successfully.');

            $this->assertSame(0, $jack->delegatees()->count());
        });
    }
}
