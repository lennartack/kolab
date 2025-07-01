<?php

namespace Tests\Browser;

use App\Auth\SecondFactor;
use Tests\Browser;
use Tests\Browser\Components\Menu;
use Tests\Browser\Components\Toast;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\Home;
use Tests\TestCaseDusk;

class LogonTest extends TestCaseDusk
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('test@logon.test');
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('test@logon.test');

        parent::tearDown();
    }

    /**
     * Test menu on logon page
     */
    public function testLogonMenu(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->visit(new Home())
                ->within(new Menu(), static function ($browser) {
                    $browser->assertMenuItems(['signup', 'support', 'login', 'lang'])
                        ->assertSeeIn('#footer-copyright', \config('app.company.copyright'))
                        ->assertSeeIn('#footer-copyright', date('Y'));
                });

            if ($browser->isDesktop()) {
                $browser->within(new Menu('footer'), static function ($browser) {
                    $browser->assertMenuItems(['signup', 'support', 'login']);
                });
            } else {
                $browser->assertMissing('#footer-menu .navbar-nav');
            }

            $browser->assertSeeLink('Forgot password?')
                ->assertSeeLink('Webmail');
        });
    }

    /**
     * Test language menu, and language change
     */
    public function testLocales(): void
    {
        $this->browse(function (Browser $browser) {
            if (!$browser->isDesktop()) {
                $this->markTestIncomplete();
            }

            $browser->visit(new Home())
                // ->plainCookie('language', '')
                ->within(new Menu(), static function ($browser) {
                    $browser->assertSeeIn('@lang', 'EN')
                        ->click('@lang');
                })
                // Switch English -> German
                ->whenAvailable('nav .dropdown-menu', static function (Browser $browser) {
                    $browser->assertElementsCount('a', 3)
                        ->assertSeeIn('a:nth-child(1)', 'EN - English')
                        ->assertSeeIn('a:nth-child(2)', 'DE - German')
                        ->assertSeeIn('a:nth-child(3)', 'FR - French')
                        ->click('a:nth-child(2)');
                })
                ->waitUntilMissing('nav .dropdown-menu')
                ->within(new Menu(), static function ($browser) {
                    $browser->assertSeeIn('@lang', 'DE');
                })
                ->waitForTextIn('#header-menu .link-login', 'EINLOGGEN')
                ->assertSeeIn('#footer-menu .link-login', 'Einloggen')
                ->assertSeeIn('@logon-button', 'Anmelden')
                // refresh the page to see if it uses the lang previously set
                ->refresh()
                ->waitForTextIn('#header-menu .link-login', 'EINLOGGEN')
                ->assertSeeIn('#footer-menu .link-login', 'Einloggen')
                ->assertSeeIn('@logon-button', 'Anmelden')
                ->within(new Menu(), static function ($browser) {
                    $browser->assertSeeIn('@lang', 'DE')
                        ->click('@lang');
                })
                // Switch German -> English
                ->whenAvailable('nav .dropdown-menu', static function (Browser $browser) {
                    $browser->assertSeeIn('a:nth-child(1)', 'Englisch')
                        ->click('a:nth-child(1)');
                })
                ->waitUntilMissing('nav .dropdown-menu')
                ->within(new Menu(), static function ($browser) {
                    $browser->assertSeeIn('@lang', 'EN');
                })
                ->waitForTextIn('#header-menu .link-login', 'LOGIN');
        });
    }

    /**
     * Test redirect to /login if user is unauthenticated
     */
    public function testRequiredAuth(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->visit('/dashboard');

            // Checks if we're really on the login page
            $browser->waitForLocation('/login')
                ->on(new Home());
        });
    }

    /**
     * Logon with wrong password/user test
     */
    public function testLogonWrongCredentials(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->visit(new Home())
                ->submitLogon('john@kolab.org', 'wrong');

            // Error message
            $browser->assertToast(Toast::TYPE_ERROR, 'Invalid username or password.');

            // Checks if we're still on the logon page
            $browser->on(new Home());
        });
    }

    /**
     * Successful logon test
     */
    public function testLogonSuccessful(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->visit(new Home())
                ->submitLogon('john@kolab.org', 'simple123', true)
                // Checks if we're really on Dashboard page
                ->on(new Dashboard())
                ->assertVisible('@links a.link-settings')
                ->assertVisible('@links a.link-domains')
                ->assertVisible('@links a.link-users')
                ->assertVisible('@links a.link-wallet')
                ->assertVisible('@links a.link-webmail')
                ->within(new Menu(), static function ($browser) {
                    $browser->assertMenuItems(['support', 'dashboard', 'logout', 'lang']);
                });

            if ($browser->isDesktop()) {
                $browser->within(new Menu('footer'), static function ($browser) {
                    $browser->assertMenuItems(['support', 'dashboard', 'logout']);
                });
            } else {
                $browser->assertMissing('#footer-menu .navbar-nav');
            }

            $browser->assertUser('john@kolab.org');

            // Assert no "Account status" for this account
            $browser->assertMissing('@status');

            // Goto /domains and assert that the link on logo element
            // leads to the dashboard
            $browser->visit('/domains')
                ->waitForText('Domains')
                ->click('a.navbar-brand')
                ->on(new Dashboard());

            // Test that visiting '/' with logged in user does not open logon form
            // but "redirects" to the dashboard
            $browser->visit('/')
                ->waitForLocation('/dashboard')
                ->on(new Dashboard());
        });
    }

    /**
     * Logout test
     *
     * @depends testLogonSuccessful
     */
    public function testLogout(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->on(new Dashboard())
                ->clearToasts();

            // Click the Logout button
            $browser->within(new Menu(), static function ($browser) {
                $browser->clickMenuItem('logout');
            });

            // We expect the logon page
            $browser->waitForLocation('/login')
                ->on(new Home());

            // with default menu
            $browser->within(new Menu(), static function ($browser) {
                $browser->assertMenuItems(['signup', 'support', 'login', 'lang']);
            });

            // Success toast message
            $browser->assertToast(Toast::TYPE_SUCCESS, 'Successfully logged out');
        });
    }

    /**
     * Test logon with an expired password (with password update)
     */
    public function testLogonExpiredPassword(): void
    {
        $this->browse(function (Browser $browser) {
            $cur_pass = 'simple123';
            $new_pass = 'ABC123456789';
            $user = $this->getTestUser('test@logon.test', ['password' => $cur_pass]);
            $user->setSetting('password_expired', now()->toDateTimeString());

            $browser->visit(new Home())
                ->submitLogon($user->email, $cur_pass, false)
                ->waitFor('@new-password-input')
                ->assertVisible('p.alert')
                ->waitFor('#new_password_policy > li:first-child > span.text-secondary')
                ->type('@new-password-input', $new_pass)
                ->type('@new-password-confirmation-input', $new_pass)
                ->waitFor('#new_password_policy > li:first-child > svg.text-success')
                ->click('@logon-button')
                ->assertToast(Toast::TYPE_SUCCESS, 'Password updated successfully.')
                ->on(new Dashboard())
                ->assertUser($user->email);

            $user->refresh();
            $this->assertTrue($user->validatePassword($new_pass));
        });

        // TODO: Test error handling
        // TODO: Test 2FA handling
    }

    /**
     * Logout by URL test
     */
    public function testLogoutByURL(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->visit(new Home())
                ->submitLogon('john@kolab.org', 'simple123', true);

            // Checks if we're really on Dashboard page
            $browser->on(new Dashboard());

            // Use /logout url, and expect the logon page
            $browser->visit('/logout')
                ->waitForLocation('/login')
                ->on(new Home());

            // with default menu
            $browser->within(new Menu(), static function ($browser) {
                $browser->assertMenuItems(['signup', 'support', 'login', 'lang']);
            });

            // Success toast message
            $browser->assertToast(Toast::TYPE_SUCCESS, 'Successfully logged out');
        });
    }

    /**
     * Test 2-Factor Authentication
     *
     * @depends testLogoutByURL
     */
    public function test2FA(): void
    {
        $this->browse(static function (Browser $browser) {
            // Test missing 2fa code
            $browser->on(new Home())
                ->type('@email-input', 'ned@kolab.org')
                ->type('@password-input', 'simple123')
                ->press('form button')
                ->waitFor('@second-factor-input.is-invalid + .invalid-feedback')
                ->assertSeeIn(
                    '@second-factor-input.is-invalid + .invalid-feedback',
                    'Second factor code is required.'
                )
                ->assertFocused('@second-factor-input')
                ->assertToast(Toast::TYPE_ERROR, 'Form validation error');

            // Test invalid code
            $browser->type('@second-factor-input', '123456')
                ->press('form button')
                ->waitUntilMissing('@second-factor-input.is-invalid')
                ->waitFor('@second-factor-input.is-invalid + .invalid-feedback')
                ->assertSeeIn(
                    '@second-factor-input.is-invalid + .invalid-feedback',
                    'Second factor code is invalid.'
                )
                ->assertFocused('@second-factor-input')
                ->assertToast(Toast::TYPE_ERROR, 'Form validation error');

            $code = SecondFactor::code('ned@kolab.org');

            // Test valid (TOTP) code
            $browser->type('@second-factor-input', $code)
                ->press('form button')
                ->waitUntilMissing('@second-factor-input.is-invalid')
                ->waitForLocation('/dashboard')
                ->on(new Dashboard());
        });
    }

    /**
     * Test redirect to the requested page after logon
     *
     * @depends test2FA
     */
    public function testAfterLogonRedirect(): void
    {
        $this->browse(static function (Browser $browser) {
            // User is logged in, invalidate the session token and visit /settings page
            $browser->execScript("localStorage.setItem('token', '123')")
                ->visit('/settings')
                ->on(new Home())
                // log in the user
                ->submitLogon('john@kolab.org', 'simple123', false)
                // wait for a "redirect" to the My account page
                ->waitForLocation('/settings');

            // User is logged in, invalidate the session token and visit root page
            // and expect to land on the logon form, and then Dashboard
            $browser->execScript("localStorage.setItem('token', '123')")
                ->visit('/')
                ->on(new Home())
                // log in the user
                ->submitLogon('john@kolab.org', 'simple123', false)
                // wait for a "redirect" to the Dashboard
                ->waitForLocation('/dashboard');
        });
    }
}
