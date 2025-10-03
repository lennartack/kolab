<?php

namespace Tests\Browser;

use App\User;
use App\VerificationCode;
use Tests\Browser;
use Tests\Browser\Components\Menu;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\Home;
use Tests\Browser\Pages\PasswordReset;
use Tests\TestCaseDusk;

class PasswordResetTest extends TestCaseDusk
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('passwordresettestdusk@' . \config('app.domain'));
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('passwordresettestdusk@' . \config('app.domain'));

        parent::tearDown();
    }

    /**
     * Test the link from logon to password-reset page
     */
    public function testLinkOnLogon(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->visit(new Home())
                ->assertSeeLink('Forgot password?')
                ->clickLink('Forgot password?')
                ->on(new PasswordReset())
                ->assertVisible('@step1');
        });
    }

    /**
     * Test 1st step of password-reset
     */
    public function testStep1(): void
    {
        $user = $this->getTestUser('passwordresettestdusk@' . \config('app.domain'));
        $user->setSetting('external_email', 'external@domain.tld');

        $this->browse(static function (Browser $browser) {
            $browser->visit(new PasswordReset());

            $browser->assertVisible('@step1');

            // Here we expect email input and submit button
            $browser->with('@step1', static function ($step) {
                $step->assertVisible('#reset_email');
                $step->assertFocused('#reset_email');
                $step->assertVisible('[type=submit]');
            });

            // Submit empty form
            $browser->with('@step1', static function ($step) {
                $step->click('[type=submit]');
                $step->assertFocused('#reset_email');
            });

            // Submit invalid email
            // We expect email input to have is-invalid class added, with .invalid-feedback element
            $browser->with('@step1', static function ($step) use ($browser) {
                $step->type('#reset_email', '@test');
                $step->click('[type=submit]');

                $step->waitFor('#reset_email.is-invalid');
                $step->waitFor('#reset_email + .invalid-feedback');
                $browser->waitFor('.toast-error');
                $browser->click('.toast-error'); // remove the toast
            });

            // Submit valid data
            $browser->with('@step1', static function ($step) {
                $step->type('#reset_email', 'passwordresettestdusk@' . \config('app.domain'));
                $step->click('[type=submit]');

                $step->assertMissing('#reset_email.is-invalid');
                $step->assertMissing('#reset_email + .invalid-feedback');
            });

            $browser->waitUntilMissing('@step2 #reset_code[value=""]');
            $browser->waitFor('@step2');
            $browser->assertMissing('@step1');
        });
    }

    /**
     * Test 2nd Step of the password reset process
     *
     * @depends testStep1
     */
    public function testStep2(): void
    {
        $user = $this->getTestUser('passwordresettestdusk@' . \config('app.domain'));
        $user->setSetting('external_email', 'external@domain.tld');

        $this->browse(function (Browser $browser) {
            $browser->assertVisible('@step2');

            // Here we expect one text input, Back and Continue buttons
            $browser->with('@step2', static function ($step) {
                $step->assertVisible('#reset_short_code');
                $step->assertFocused('#reset_short_code');
                $step->assertVisible('[type=button]');
                $step->assertVisible('[type=submit]');
            });

            // Test Back button functionality
            $browser->click('@step2 [type=button]');
            $browser->waitFor('@step1');
            $browser->assertFocused('@step1 #reset_email');
            $browser->assertMissing('@step2');

            // Submit valid Step 1 data (again)
            $browser->with('@step1', static function ($step) {
                $step->type('#reset_email', 'passwordresettestdusk@' . \config('app.domain'));
                $step->click('[type=submit]');
            });

            $browser->waitFor('@step2');
            $browser->assertMissing('@step1');

            // Submit invalid code
            // We expect code input to have is-invalid class added, with .invalid-feedback element
            $browser->with('@step2', static function ($step) use ($browser) {
                $step->type('#reset_short_code', 'XXXXX');
                $step->click('[type=submit]');

                $browser->waitFor('.toast-error');

                $step->waitFor('#reset_short_code.is-invalid')
                    ->assertVisible('#reset_short_code.is-invalid')
                    ->assertVisible('#reset_short_code + .invalid-feedback')
                    ->assertFocused('#reset_short_code');

                $browser->click('.toast-error'); // remove the toast
            });

            // Submit valid code
            // We expect error state on code input to be removed, and Step 3 form visible
            $browser->with('@step2', function ($step) {
                // Get the code and short_code from database
                // FIXME: Find a nice way to read javascript data without using hidden inputs
                $code = $step->value('#reset_code');

                $this->assertNotEmpty($code);

                $code = VerificationCode::find($code);

                $step->type('#reset_short_code', $code->short_code);
                $step->click('[type=submit]');

                $step->assertMissing('#reset_short_code.is-invalid');
                $step->assertMissing('#reset_short_code + .invalid-feedback');
            });

            $browser->waitFor('@step3');
            $browser->assertMissing('@step2');
        });
    }

    /**
     * Test 3rd Step of the password reset process
     *
     * @depends testStep2
     */
    public function testStep3(): void
    {
        $user = $this->getTestUser('passwordresettestdusk@' . \config('app.domain'));
        $user->setSetting('external_email', 'external@domain.tld');
        $user->setSetting('password_policy', 'upper,digit');

        $this->browse(function (Browser $browser) {
            $browser->assertVisible('@step3')
                ->clearToasts();

            // Here we expect 2 text inputs, Back and Continue buttons
            $browser->with('@step3', static function (Browser $step) {
                $step->assertVisible('#reset_password')
                    ->assertVisible('#reset_password_confirmation')
                    ->assertVisible('[type=button]')
                    ->assertVisible('[type=submit]')
                    ->assertFocused('#reset_password');
            });

            // Test Back button
            $browser->click('@step3 [type=button]')
                ->waitFor('@step2')
                ->assertFocused('@step2 #reset_short_code')
                ->assertMissing('@step3')
                ->assertMissing('@step1');

            // TODO: Test form reset when going back

            // Because the verification code is removed in tearDown()
            // we'll start from the beginning (Step 1)
            $browser->click('@step2 [type=button]')
                ->waitFor('@step1')
                ->assertFocused('@step1 #reset_email')
                ->assertMissing('@step3')
                ->assertMissing('@step2');

            // Submit valid data
            $browser->with('@step1', static function ($step) {
                $step->type('#reset_email', 'passwordresettestdusk@' . \config('app.domain'));
                $step->click('[type=submit]');
            });

            $browser->waitFor('@step2')
                ->waitUntilMissing('@step2 #reset_code[value=""]');

            // Submit valid code again
            $browser->with('@step2', function ($step) {
                $code = $step->value('#reset_code');

                $this->assertNotEmpty($code);

                $code = VerificationCode::find($code);

                $step->type('#reset_short_code', $code->short_code);
                $step->click('[type=submit]');
            });

            $browser->waitFor('@step3');

            // Submit invalid data
            $browser->with('@step3', static function ($step) use ($browser) {
                $step->waitFor('#reset_password')
                    ->assertFocused('#reset_password')
                    ->whenAvailable('#reset_password_policy', static function (Browser $browser) {
                        $browser->assertElementsCount('li', 2)
                            ->assertMissing('li:first-child svg.text-success')
                            ->assertSeeIn('li:first-child small', "Password contains an upper-case character")
                            ->assertMissing('li:last-child svg.text-success')
                            ->assertSeeIn('li:last-child small', "Password contains a digit");
                    })
                    ->type('#reset_password', 'A2345678')
                    ->type('#reset_password_confirmation', '123456789')
                    ->with('#reset_password_policy', static function (Browser $browser) {
                        $browser->waitFor('li:first-child svg.text-success')
                            ->waitFor('li:last-child svg.text-success');
                    });

                $step->click('[type=submit]');

                $browser->waitFor('.toast-error');

                $step->waitFor('#reset_password.is-invalid')
                    ->assertVisible('#reset_password_input .invalid-feedback')
                    ->assertFocused('#reset_password');

                $browser->click('.toast-error'); // remove the toast
            });

            // Submit valid data
            $browser->with('@step3', static function ($step) {
                $step->type('#reset_password_confirmation', 'A2345678')
                    ->click('[type=submit]');
            });

            $browser->waitUntilMissing('@step3');

            // At this point we should be auto-logged-in to dashboard
            $browser->on(new Dashboard());

            // FIXME: Is it enough to be sure user is logged in?
        });
    }

    /**
     * Test password-reset via a link
     */
    public function testResetViaLink(): void
    {
        $user = $this->getTestUser('passwordresettestdusk@' . \config('app.domain'));
        $user->setSetting('external_email', 'external@domain.tld');

        $code = new VerificationCode(['mode' => VerificationCode::MODE_PASSWORD]);
        $user->verificationCodes()->save($code);

        $this->browse(function (Browser $browser) use ($code) {
            // Test a valid link
            $browser->visit("/password-reset/{$code->short_code}-{$code->code}")
                ->on(new PasswordReset())
                ->waitFor('@step3')
                ->assertMissing('@step1')
                ->assertMissing('@step2')
                ->with('@step3', static function ($step) {
                    $step->type('#reset_password', 'A2345678')
                        ->type('#reset_password_confirmation', 'A2345678')
                        ->click('[type=submit]');
                })
                ->waitUntilMissing('@step3')
                // At this point we should be auto-logged-in to dashboard
                ->on(new Dashboard())
                ->within(new Menu(), static function ($browser) {
                    $browser->clickMenuItem('logout');
                });

            $this->assertNull(VerificationCode::find($code->code));

            // Test an invalid link
            $browser->visit("/password-reset/{$code->short_code}-{$code->code}")
                ->assertErrorPage(404, 'The password reset code is expired or invalid.');
        });
    }

    /**
     * Test password reset process for a user with 2FA enabled.
     */
    public function testResetWith2FA(): void
    {
        $this->markTestIncomplete();
    }
}
