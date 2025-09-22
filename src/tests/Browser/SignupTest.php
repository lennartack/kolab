<?php

namespace Tests\Browser;

use App\Discount;
use App\Domain;
use App\Payment;
use App\Plan;
use App\ReferralProgram;
use App\SignupCode;
use App\SignupInvitation;
use App\SignupToken;
use App\User;
use Tests\Browser;
use Tests\Browser\Components\Menu;
use Tests\Browser\Components\Toast;
use Tests\Browser\Pages\Dashboard;
use Tests\Browser\Pages\PaymentMollie;
use Tests\Browser\Pages\PaymentStatus;
use Tests\Browser\Pages\Signup;
use Tests\TestCaseDusk;

class SignupTest extends TestCaseDusk
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('signuptestdusk@' . \config('app.domain'));
        $this->deleteTestUser('admin@user-domain-signup.com');
        $this->deleteTestDomain('user-domain-signup.com');

        Plan::whereNot('mode', Plan::MODE_EMAIL)->update(['mode' => Plan::MODE_EMAIL]);
        Plan::withEnvTenantContext()->where('title', 'individual')->update(['hidden' => false]);
        SignupToken::truncate();
        ReferralProgram::query()->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('signuptestdusk@' . \config('app.domain'));
        $this->deleteTestUser('admin@user-domain-signup.com');
        $this->deleteTestDomain('user-domain-signup.com');
        SignupInvitation::truncate();

        Plan::whereNot('mode', Plan::MODE_EMAIL)->update(['mode' => Plan::MODE_EMAIL]);
        Plan::withEnvTenantContext()->where('title', 'individual')->update(['hidden' => false]);
        SignupToken::truncate();
        ReferralProgram::query()->delete();

        parent::tearDown();
    }

    /**
     * Test signup code verification with a link
     */
    public function testSignupCodeByLink(): void
    {
        // Test invalid code (invalid format)
        $this->browse(static function (Browser $browser) {
            // Register Signup page element selectors we'll be using
            $browser->onWithoutAssert(new Signup());

            // TODO: Test what happens if user is logged in

            $browser->visit('/signup/invalid-code');

            // TODO: According to https://github.com/vuejs/vue-router/issues/977
            // it is not yet easily possible to display error page component (route)
            // without changing the URL
            // TODO: Instead of css selector we should probably define page/component
            // and use it instead
            $browser->waitFor('#error-page');
        });

        // Test invalid code (valid format)
        $this->browse(static function (Browser $browser) {
            $browser->visit('/signup/XXXXX-code');

            // FIXME: User will not be able to continue anyway, so we should
            //        either display 1st step or 404 error page
            $browser->waitFor('@step1')
                ->assertToast(Toast::TYPE_ERROR, 'Form validation error');
        });

        // Test valid code
        $this->browse(function (Browser $browser) {
            $code = SignupCode::create([
                'email' => 'User@example.org',
                'first_name' => 'User',
                'last_name' => 'Name',
                'plan' => 'individual',
                'voucher' => '',
            ]);

            $browser->visit('/signup/' . $code->short_code . '-' . $code->code)
                ->waitFor('@step3')
                ->assertMissing('@step1')
                ->assertMissing('@step2');

            // FIXME: Find a nice way to read javascript data without using hidden inputs
            $this->assertSame($code->code, $browser->value('@step2 #signup_code'));

            // TODO: Test if the signup process can be completed
        });
    }

    /**
     * Test signup "welcome" page
     */
    public function testSignupStep0(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->visit(new Signup());

            $browser->assertVisible('@step0')
                ->assertMissing('@step1')
                ->assertMissing('@step2')
                ->assertMissing('@step3');

            $browser->within(new Menu(), static function ($browser) {
                $browser->assertMenuItems(['support', 'signup', 'login', 'lang'], 'signup');
            });

            $browser->waitFor('@step0 .plan-selector .card');

            // Assert first plan box and press the button
            $browser->with('@step0 .plan-selector .plan-individual', static function ($step) {
                $step->assertVisible('button')
                    ->assertSeeIn('button', 'Individual Account')
                    ->assertVisible('.plan-description')
                    ->click('button');
            });

            $browser->waitForLocation('/signup/individual')
                ->assertVisible('@step1')
                ->assertSeeIn('.card-title', 'Sign Up - Step 1/3')
                ->assertMissing('@step0')
                ->assertMissing('@step2')
                ->assertMissing('@step3')
                ->assertFocused('@step1 #signup_first_name');

            // Click Back button
            $browser->click('@step1 [type=button]')
                ->waitForLocation('/signup')
                ->assertVisible('@step0')
                ->assertMissing('@step1')
                ->assertMissing('@step2')
                ->assertMissing('@step3');

            // Choose the group account plan
            $browser->click('@step0 .plan-selector .plan-group button')
                ->waitForLocation('/signup/group')
                ->assertVisible('@step1')
                ->assertMissing('@step0')
                ->assertMissing('@step2')
                ->assertMissing('@step3')
                ->assertFocused('@step1 #signup_first_name');

            // TODO: Test if 'plan' variable is set properly in vue component
        });
    }

    /**
     * Test 1st step of the signup process
     */
    public function testSignupStep1(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->visit('/signup/individual')
                ->onWithoutAssert(new Signup());

            // Here we expect two text inputs and Back and Continue buttons
            $browser->with('@step1', static function ($step) {
                $step->waitFor('#signup_last_name')
                    ->assertSeeIn('.card-title', 'Sign Up - Step 1/3')
                    ->assertVisible('#signup_first_name')
                    ->assertFocused('#signup_first_name')
                    ->assertVisible('#signup_email')
                    ->assertVisible('[type=button]')
                    ->assertVisible('[type=submit]');
            });

            // Submit empty form
            // Email is required, so after pressing Submit
            // we expect focus to be moved to the email input
            $browser->with('@step1', static function ($step) {
                $step->click('[type=submit]');
                $step->assertFocused('#signup_email');
            });

            $browser->within(new Menu(), static function ($browser) {
                $browser->assertMenuItems(['support', 'signup', 'login', 'lang'], 'signup');
            });

            // Submit invalid email, and first_name
            // We expect both inputs to have is-invalid class added, with .invalid-feedback element
            $browser->with('@step1', static function ($step) {
                $step->type('#signup_first_name', str_repeat('a', 250))
                    ->type('#signup_email', '@test')
                    ->click('[type=submit]')
                    ->waitFor('#signup_email.is-invalid')
                    ->assertVisible('#signup_first_name.is-invalid')
                    ->assertVisible('#signup_email + .invalid-feedback')
                    ->assertVisible('#signup_last_name + .invalid-feedback')
                    ->assertToast(Toast::TYPE_ERROR, 'Form validation error');
            });

            // Submit valid data
            // We expect error state on email input to be removed, and Step 2 form visible
            $browser->with('@step1', static function ($step) {
                $step->type('#signup_first_name', 'Test')
                    ->type('#signup_last_name', 'User')
                    ->type('#signup_email', 'BrowserSignupTestUser1@kolab.org')
                    ->click('[type=submit]')
                    ->assertMissing('#signup_email.is-invalid')
                    ->assertMissing('#signup_email + .invalid-feedback');
            });

            $browser->waitUntilMissing('@step2 #signup_code[value=""]');
            $browser->waitFor('@step2');
            $browser->assertMissing('@step1');
        });
    }

    /**
     * Test 2nd Step of the signup process
     *
     * @depends testSignupStep1
     */
    public function testSignupStep2(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->assertVisible('@step2')
                ->assertSeeIn('@step2 .card-title', 'Sign Up - Step 2/3')
                ->assertMissing('@step0')
                ->assertMissing('@step1')
                ->assertMissing('@step3');

            // Here we expect one text input, Back and Continue buttons
            $browser->with('@step2', static function ($step) {
                $step->assertVisible('#signup_short_code')
                    ->assertFocused('#signup_short_code')
                    ->assertVisible('[type=button]')
                    ->assertVisible('[type=submit]');
            });

            // Test Back button functionality
            $browser->click('@step2 [type=button]')
                ->waitFor('@step1')
                ->assertFocused('@step1 #signup_first_name')
                ->assertMissing('@step2');

            // Submit valid Step 1 data (again)
            $browser->with('@step1', static function ($step) {
                $step->type('#signup_first_name', 'User')
                    ->type('#signup_last_name', 'User')
                    ->type('#signup_email', 'BrowserSignupTestUser1@kolab.org')
                    ->click('[type=submit]');
            });

            $browser->waitFor('@step2');
            $browser->assertMissing('@step1');

            // Submit invalid code
            // We expect code input to have is-invalid class added, with .invalid-feedback element
            $browser->with('@step2', static function ($step) {
                $step->type('#signup_short_code', 'XXXXX');
                $step->click('[type=submit]');

                $step->waitFor('#signup_short_code.is-invalid')
                    ->assertVisible('#signup_short_code + .invalid-feedback')
                    ->assertFocused('#signup_short_code')
                    ->assertToast(Toast::TYPE_ERROR, 'Form validation error');
            });

            // Submit valid code
            // We expect error state on code input to be removed, and Step 3 form visible
            $browser->with('@step2', function ($step) {
                // Get the code and short_code from database
                // FIXME: Find a nice way to read javascript data without using hidden inputs
                $code = $step->value('#signup_code');

                $this->assertNotEmpty($code);

                $code = SignupCode::find($code);

                $step->type('#signup_short_code', $code->short_code);
                $step->click('[type=submit]');

                $step->assertMissing('#signup_short_code.is-invalid');
                $step->assertMissing('#signup_short_code + .invalid-feedback');
            });

            $browser->waitFor('@step3');
            $browser->assertMissing('@step2');
        });
    }

    /**
     * Test 3rd Step of the signup process
     *
     * @depends testSignupStep2
     */
    public function testSignupStep3(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->assertVisible('@step3');

            // Here we expect 3 text inputs, Back and Continue buttons
            $browser->with('@step3', static function ($step) {
                $domains = Domain::getPublicDomains();
                $domains_count = count($domains);

                $step->assertSeeIn('.card-title', 'Sign Up - Step 3/3')
                    ->assertMissing('#signup_last_name')
                    ->assertMissing('#signup_first_name')
                    ->assertVisible('#signup_login')
                    ->assertVisible('#signup_password')
                    ->assertVisible('#signup_password_confirmation')
                    ->assertVisible('select#signup_domain')
                    ->assertMissing('#custom_domain')
                    ->assertMissing('input#signup_domain')
                    ->assertElementsCount('select#signup_domain option', $domains_count, false)
                    ->assertText('select#signup_domain option:nth-child(1)', $domains[0])
                    ->assertValue('select#signup_domain option:nth-child(1)', $domains[0])
                    ->assertText('select#signup_domain option:nth-child(2)', $domains[1])
                    ->assertValue('select#signup_domain option:nth-child(2)', $domains[1])
                    ->assertVisible('[type=button]')
                    ->assertVisible('[type=submit]')
                    ->assertSeeIn('[type=submit]', 'Submit')
                    ->assertFocused('#signup_login')
                    ->assertSelected('select#signup_domain', \config('app.domain'))
                    ->assertValue('#signup_login', '')
                    ->assertValue('#signup_password', '')
                    ->assertValue('#signup_password_confirmation', '')
                    ->with('#signup_password_policy', static function (Browser $browser) {
                        $browser->assertElementsCount('li', 2)
                            ->assertMissing('li:first-child svg.text-success')
                            ->assertSeeIn('li:first-child small', "Minimum password length: 6 characters")
                            ->assertMissing('li:last-child svg.text-success')
                            ->assertSeeIn('li:last-child small', "Maximum password length: 255 characters");
                    });

                // TODO: Test domain selector
            });

            // Test Back button
            $browser->click('@step3 [type=button]');
            $browser->waitFor('@step2');
            $browser->assertFocused('@step2 #signup_short_code');
            $browser->assertMissing('@step3');

            // TODO: Test form reset when going back

            // Submit valid code again
            $browser->with('@step2', function ($step) {
                $code = $step->value('#signup_code');

                $this->assertNotEmpty($code);

                $code = SignupCode::find($code);

                $step->type('#signup_short_code', $code->short_code);
                $step->click('[type=submit]');
            });

            $browser->waitFor('@step3');

            // Submit invalid data
            $browser->with('@step3', static function ($step) {
                $step->assertFocused('#signup_login')
                    ->type('#signup_login', '*')
                    ->type('#signup_password', '12345678')
                    ->type('#signup_password_confirmation', '123456789')
                    ->with('#signup_password_policy', static function (Browser $browser) {
                        $browser->waitFor('li:first-child svg.text-success')
                            ->waitFor('li:last-child svg.text-success');
                    })
                    ->click('[type=submit]')
                    ->waitFor('#signup_login.is-invalid')
                    ->assertVisible('#signup_domain + .invalid-feedback')
                    ->assertVisible('#signup_password.is-invalid')
                    ->assertVisible('#signup_password_input .invalid-feedback')
                    ->assertFocused('#signup_login')
                    ->assertToast(Toast::TYPE_ERROR, 'Form validation error');
            });

            // Submit invalid data (valid login, invalid password)
            $browser->with('@step3', static function ($step) {
                $step->type('#signup_login', 'SignupTestDusk')
                    ->click('[type=submit]')
                    ->waitFor('#signup_password.is-invalid')
                    ->assertVisible('#signup_password_input .invalid-feedback')
                    ->assertMissing('#signup_login.is-invalid')
                    ->assertMissing('#signup_domain + .invalid-feedback')
                    ->assertFocused('#signup_password')
                    ->assertToast(Toast::TYPE_ERROR, 'Form validation error');
            });

            // Submit valid data
            $browser->with('@step3', static function ($step) {
                $step->type('#signup_password_confirmation', '12345678');

                $step->click('[type=submit]');
            });

            // At this point we should be auto-logged-in to dashboard
            $browser->waitUntilMissing('@step3')
                ->waitUntilMissing('.app-loader')
                ->on(new Dashboard())
                ->assertUser('signuptestdusk@' . \config('app.domain'))
                ->assertVisible('@links a.link-settings')
                ->assertMissing('@links a.link-domains')
                ->assertVisible('@links a.link-users')
                ->assertVisible('@links a.link-wallet');

            // Logout the user
            $browser->within(new Menu(), static function ($browser) {
                $browser->clickMenuItem('logout');
            });
        });
    }

    /**
     * Test signup for a group account
     */
    public function testSignupGroup(): void
    {
        $this->browse(static function (Browser $browser) {
            $browser->visit(new Signup());

            // Choose the group account plan
            $browser->waitFor('@step0 .plan-group button')
                ->click('@step0 .plan-group button');

            // Submit valid data
            // We expect error state on email input to be removed, and Step 2 form visible
            $browser->whenAvailable('@step1', static function ($step) {
                $step->type('#signup_first_name', 'Test')
                    ->type('#signup_last_name', 'User')
                    ->type('#signup_email', 'BrowserSignupTestUser1@kolab.org')
                    ->click('[type=submit]');
            });

            // Submit valid code
            $browser->whenAvailable('@step2', static function ($step) {
                // Get the code and short_code from database
                // FIXME: Find a nice way to read javascript data without using hidden inputs
                $code = $step->value('#signup_code');
                $code = SignupCode::find($code);

                $step->type('#signup_short_code', $code->short_code)
                    ->click('[type=submit]');
            });

            // Here we expect text inputs, as well as domain selector, and buttons
            $browser->whenAvailable('@step3', static function ($step) {
                $domains = Domain::getPublicDomains();
                $domains_count = count($domains);

                $step->assertVisible('#signup_login')
                    ->assertVisible('#signup_password')
                    ->assertVisible('#signup_password_confirmation')
                    ->assertVisible('select#signup_domain')
                    ->assertMissing('input#signup_domain')
                    ->assertVisible('#custom_domain')
                    ->assertElementsCount('select#signup_domain option', $domains_count, false)
                    ->assertText('select#signup_domain option:nth-child(1)', $domains[0])
                    ->assertValue('select#signup_domain option:nth-child(1)', $domains[0])
                    ->assertText('select#signup_domain option:nth-child(2)', $domains[1])
                    ->assertValue('select#signup_domain option:nth-child(2)', $domains[1])
                    ->assertVisible('[type=button]')
                    ->assertVisible('[type=submit]')
                    ->assertFocused('#signup_login')
                    ->click('#custom_domain + label')
                    ->assertVisible('input#signup_domain')
                    ->assertMissing('select#signup_domain')
                    ->assertValue('input#signup_domain', '')
                    ->assertValue('#signup_login', '')
                    ->assertValue('#signup_password', '')
                    ->assertValue('#signup_password_confirmation', '');
            });

            // Submit invalid login and password data
            $browser->with('@step3', static function ($step) {
                $step->type('#signup_login', '*')
                    ->type('#signup_domain', 'test.com')
                    ->type('#signup_password', '12345678')
                    ->type('#signup_password_confirmation', '123456789')
                    ->click('[type=submit]')
                    ->waitFor('#signup_login.is-invalid')
                    ->assertVisible('#signup_domain + .invalid-feedback')
                    ->assertVisible('#signup_password.is-invalid')
                    ->assertVisible('#signup_password_input .invalid-feedback')
                    ->assertFocused('#signup_login')
                    ->assertToast(Toast::TYPE_ERROR, 'Form validation error');
            });

            // Submit invalid domain
            $browser->with('@step3', static function ($step) {
                $step->type('#signup_login', 'admin')
                    ->type('#signup_domain', 'aaa')
                    ->type('#signup_password', '12345678')
                    ->type('#signup_password_confirmation', '12345678')
                    ->click('[type=submit]')
                    ->waitUntilMissing('#signup_login.is-invalid')
                    ->waitFor('#signup_domain.is-invalid + .invalid-feedback')
                    ->assertMissing('#signup_password.is-invalid')
                    ->assertMissing('#signup_password_input .invalid-feedback')
                    ->assertFocused('#signup_domain')
                    ->assertToast(Toast::TYPE_ERROR, 'Form validation error');
            });

            // Submit invalid domain
            $browser->with('@step3', static function ($step) {
                $step->type('#signup_domain', 'user-domain-signup.com')
                    ->click('[type=submit]');
            });

            // At this point we should be auto-logged-in to dashboard
            $browser->waitUntilMissing('@step3')
                ->waitUntilMissing('.app-loader')
                ->on(new Dashboard())
                ->assertUser('admin@user-domain-signup.com')
                ->assertVisible('@links a.link-settings')
                ->assertVisible('@links a.link-domains')
                ->assertVisible('@links a.link-users')
                ->assertVisible('@links a.link-wallet');

            $browser->within(new Menu(), static function ($browser) {
                $browser->clickMenuItem('logout');
            });
        });
    }

    /**
     * Test more signup cases
     */
    public function testSignupGroupWithPublicDomain(): void
    {
        // We also test skipping plan selection if there's only one plan active
        $plan = Plan::withEnvTenantContext()->where('title', 'individual')->first();
        $plan->hidden = true;
        $plan->save();

        $this->browse(static function (Browser $browser) {
            $browser->visit(new Signup());

            // Submit valid data
            $browser->whenAvailable('@step1', static function ($step) {
                $step->type('#signup_first_name', 'Test')
                    ->type('#signup_last_name', 'User')
                    ->type('#signup_email', 'BrowserSignupTestUser1@kolab.org')
                    ->click('[type=submit]');
            });

            // Submit valid code
            $browser->whenAvailable('@step2', static function ($step) {
                // Get the code and short_code from database
                $code = $step->value('#signup_code');
                $code = SignupCode::find($code);

                $step->type('#signup_short_code', $code->short_code)
                    ->click('[type=submit]');
            });

            // Here we expect text inputs, as well as domain selector, and buttons
            $browser->whenAvailable('@step3', static function ($step) {
                $step->assertVisible('#signup_login')
                    ->assertVisible('#signup_password')
                    ->assertVisible('#signup_password_confirmation')
                    ->assertVisible('select#signup_domain')
                    ->assertMissing('input#signup_domain')
                    ->assertVisible('#custom_domain')
                    ->assertValue('select#signup_domain option:checked', \config('app.domain'))
                    ->type('#signup_login', 'signuptestdusk')
                    ->type('#signup_password', '12345678')
                    ->type('#signup_password_confirmation', '12345678')
                    ->click('[type=submit]');
            });

            // At this point we should be auto-logged-in to dashboard
            $browser->waitUntilMissing('@step3')
                ->waitUntilMissing('.app-loader')
                ->on(new Dashboard())
                ->assertUser('signuptestdusk@' . \config('app.domain'))
                ->assertVisible('@links a.link-settings')
                ->assertVisible('@links a.link-domains')
                ->assertVisible('@links a.link-users')
                ->assertVisible('@links a.link-wallet')
                ->assertMissing('@links a.link-distlists')
                ->assertMissing('@links a.link-shared-folders')
                ->assertMissing('@links a.link-resources');

            $browser->within(new Menu(), static function ($browser) {
                $browser->clickMenuItem('logout');
            });
        });
    }

    /**
     * Test signup with a mandate plan, also the UI lock
     *
     * @group mollie
     */
    public function testSignupMandate(): void
    {
        if (!\config('services.mollie.key')) {
            $this->markTestSkipped('No MOLLIE_KEY');
        }

        // Test the individual plan
        $plan = Plan::withEnvTenantContext()->where('title', 'individual')->first();
        $plan->mode = Plan::MODE_MANDATE;
        $plan->save();

        $this->browse(static function (Browser $browser) {
            $browser->withConfig(['services.payment_provider' => 'mollie'])
                ->visit(new Signup())
                ->waitFor('@step0 .plan-individual button')
                ->click('@step0 .plan-individual button')
                // Test Back button
                ->whenAvailable('@step3', static function ($browser) {
                    $browser->click('button[type=button]');
                })
                ->whenAvailable('@step0', static function ($browser) {
                    $browser->click('.plan-individual button');
                })
                // Test submit
                ->whenAvailable('@step3', static function ($browser) {
                    $domains = Domain::getPublicDomains();
                    $domains_count = count($domains);

                    $browser->assertMissing('.card-title')
                        ->waitFor('select#signup_domain option:checked')
                        ->assertElementsCount('select#signup_domain option', $domains_count, false)
                        ->assertText('select#signup_domain option:nth-child(1)', $domains[0])
                        ->assertValue('select#signup_domain option:nth-child(1)', $domains[0])
                        ->type('#signup_login', 'signuptestdusk')
                        ->type('#signup_password', '12345678')
                        ->type('#signup_password_confirmation', '12345678')
                        ->click('[type=submit]');
                })
                ->whenAvailable('@step4', static function ($browser) {
                    $browser->assertSeeIn('h4', 'The account is about to be created!')
                        ->assertSeeIn('h5', 'You are choosing a monthly subscription')
                        ->assertVisible('#summary-content')
                        ->assertElementsCount('#summary-content + p.credit-cards img', 2)
                        ->assertVisible('#summary-summary')
                        ->assertSeeIn('button.btn-primary', 'Subscribe')
                        ->assertSeeIn('button.btn-secondary', 'Back')
                        ->click('button.btn-secondary');
                })
                ->whenAvailable('@step3', static function ($browser) {
                    $browser->assertValue('#signup_login', 'signuptestdusk')
                        ->click('[type=submit]');
                })
                ->whenAvailable('@step4', static function ($browser) {
                    $browser->click('button.btn-primary');
                })
                ->on(new PaymentMollie())
                ->assertSeeIn('@title', 'Auto-Payment Setup')
                ->submitPayment('open')
                ->on(new PaymentStatus())
                ->assertSeeIn('@lock-alert', 'The account is locked')
                ->assertSeeIn('@content', 'Checking the status...')
                ->assertSeeIn('@button', 'Try again');
        });

        $user = User::where('email', 'signuptestdusk@' . \config('app.domain'))->first();
        $this->assertSame($plan->id, $user->getSetting('plan_id'));
        $this->assertFalse($user->isActive());

        // Refresh and see that the account is still locked
        $this->browse(function (Browser $browser) use ($user) {
            $browser->visit('/dashboard')
                ->on(new PaymentStatus())
                ->assertSeeIn('@lock-alert', 'The account is locked')
                ->assertSeeIn('@content', 'Checking the status...');

            // Mark the payment paid, and activate the user in background,
            // expect unlock and redirect to the dashboard
            // TODO: Move this to a separate tests file for PaymentStatus page
            $payment = $user->wallets()->first()->payments()->first();
            $payment->credit('Test');
            $payment->status = Payment::STATUS_PAID;
            $payment->save();
            $this->assertTrue($user->fresh()->isActive());

            $browser->waitForLocation('/dashboard', 10)
                ->within(new Menu(), static function ($browser) {
                    $browser->clickMenuItem('logout');
                });
        });

        // TODO: Test the 'Try again' button on /payment/status page
    }

    /**
     * Test signup with a mandate plan with a discount=100%
     */
    public function testSignupMandateDiscount100Percent(): void
    {
        // Test the individual plan
        $plan = Plan::withEnvTenantContext()->where('title', 'individual')->first();
        $plan->mode = Plan::MODE_MANDATE;
        $plan->save();

        $this->browse(static function (Browser $browser) {
            $browser->visit(new Signup())
                ->waitFor('@step0 .plan-individual button')
                ->click('@step0 .plan-individual button')
                ->whenAvailable('@step3', static function ($browser) {
                    $browser->type('#signup_login', 'signuptestdusk')
                        ->type('#signup_password', '12345678')
                        ->type('#signup_password_confirmation', '12345678')
                        ->type('#signup_voucher', 'FREE')
                        ->click('[type=submit]');
                })
                ->whenAvailable('@step4', static function ($browser) {
                    $browser->assertSeeIn('h4', 'The account is about to be created!')
                        ->assertSeeIn('#summary-content', 'You are signing up for an account with 100% discount.')
                        ->assertMissing('#summary-summary')
                        ->assertSeeIn('button.btn-primary', 'Subscribe')
                        ->assertSeeIn('button.btn-secondary', 'Back')
                        ->click('button.btn-primary');
                })
                ->waitUntilMissing('@step4')
                ->on(new Dashboard())
                ->within(new Menu(), static function ($browser) {
                    $browser->clickMenuItem('logout');
                });
        });

        $user = User::where('email', 'signuptestdusk@' . \config('app.domain'))->first();
        $discount = Discount::where('discount', 100)->first();

        $this->assertSame($plan->id, $user->getSetting('plan_id'));
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isRestricted());
        $this->assertSame($discount->id, $user->wallets->first()->discount_id);
    }

    /**
     * Test signup with a token plan
     */
    public function testSignupToken(): void
    {
        // Test the individual plan
        $plan = Plan::withEnvTenantContext()->where('title', 'individual')->first();
        $plan->update(['mode' => Plan::MODE_TOKEN]);

        // Register a valid token
        SignupToken::create(['id' => '1234567890', 'plans' => [$plan->id]]);

        $this->browse(static function (Browser $browser) {
            $browser->visit(new Signup())
                ->waitFor('@step0 .plan-individual button')
                ->click('@step0 .plan-individual button')
                // Step 1
                ->whenAvailable('@step1', static function ($browser) {
                    $browser->assertSeeIn('.card-title', 'Sign Up - Step 1/2')
                        ->type('#signup_first_name', 'Test')
                        ->type('#signup_last_name', 'User')
                        ->assertMissing('#signup_email')
                        ->type('#signup_token', '1234')
                        // invalid token
                        ->click('[type=submit]')
                        ->waitFor('#signup_token.is-invalid')
                        ->assertVisible('#signup_token + .invalid-feedback')
                        ->assertFocused('#signup_token')
                        ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                        // valid token
                        ->type('#signup_token', '1234567890')
                        ->click('[type=submit]');
                })
                // Step 2
                ->whenAvailable('@step3', static function ($browser) {
                    $domains = Domain::getPublicDomains();
                    $domains_count = count($domains);

                    $browser->assertSeeIn('.card-title', 'Sign Up - Step 2/2')
                        ->assertElementsCount('select#signup_domain option', $domains_count, false)
                        ->assertText('select#signup_domain option:nth-child(1)', $domains[0])
                        ->assertValue('select#signup_domain option:nth-child(1)', $domains[0])
                        ->type('#signup_login', 'signuptestdusk')
                        ->type('#signup_password', '12345678')
                        ->type('#signup_password_confirmation', '12345678')
                        ->click('[type=submit]');
                })
                ->waitUntilMissing('@step3')
                ->on(new Dashboard())
                ->within(new Menu(), static function ($browser) {
                    $browser->clickMenuItem('logout');
                });
        });

        $user = User::where('email', 'signuptestdusk@' . \config('app.domain'))->first();
        $this->assertNull($user->getSetting('external_email'));

        // Test the group plan
        $plan = Plan::withEnvTenantContext()->where('title', 'group')->first();
        $plan->update(['mode' => Plan::MODE_TOKEN]);

        // Register a valid token
        SignupToken::create(['id' => 'abcdefghijk', 'plans' => [$plan->id]]);

        $this->browse(static function (Browser $browser) {
            $browser->visit(new Signup())
                ->waitFor('@step0 .plan-group button')
                ->click('@step0 .plan-group button')
                // Step 1
                ->whenAvailable('@step1', static function ($browser) {
                    $browser->assertSeeIn('.card-title', 'Sign Up - Step 1/2')
                        ->type('#signup_first_name', 'Test')
                        ->type('#signup_last_name', 'User')
                        ->assertMissing('#signup_email')
                        ->type('#signup_token', '1234')
                        // invalid token
                        ->click('[type=submit]')
                        ->waitFor('#signup_token.is-invalid')
                        ->assertVisible('#signup_token + .invalid-feedback')
                        ->assertFocused('#signup_token')
                        ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                        // valid token
                        ->type('#signup_token', 'abcdefghijk')
                        ->click('[type=submit]');
                })
                // Step 2
                ->whenAvailable('@step3', static function ($browser) {
                    $browser->assertSeeIn('.card-title', 'Sign Up - Step 2/2')
                        ->click('#custom_domain')
                        ->type('input#signup_domain', 'user-domain-signup.com')
                        ->type('#signup_login', 'admin')
                        ->type('#signup_password', '12345678')
                        ->type('#signup_password_confirmation', '12345678')
                        ->click('[type=submit]');
                })
                ->waitUntilMissing('@step3')
                ->on(new Dashboard())
                ->within(new Menu(), static function ($browser) {
                    $browser->clickMenuItem('logout');
                });
        });

        $user = User::where('email', 'admin@user-domain-signup.com')->first();
        $this->assertNull($user->getSetting('external_email'));
    }

    /**
     * Test signup with voucher
     */
    public function testSignupVoucherLink(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/signup/voucher/TEST')
                ->onWithoutAssert(new Signup())
                ->waitUntilMissing('.app-loader')
                ->waitFor('@step0')
                ->click('.plan-individual button')
                ->whenAvailable('@step1', static function (Browser $browser) {
                    $browser->type('#signup_first_name', 'Test')
                        ->type('#signup_last_name', 'User')
                        ->type('#signup_email', 'BrowserSignupTestUser1@kolab.org')
                        ->click('[type=submit]');
                })
                ->whenAvailable('@step2', function (Browser $browser) {
                    // Get the code and short_code from database
                    // FIXME: Find a nice way to read javascript data without using hidden inputs
                    $code = $browser->value('#signup_code');

                    $this->assertNotEmpty($code);

                    $code = SignupCode::find($code);

                    $browser->type('#signup_short_code', $code->short_code)
                        ->click('[type=submit]');
                })
                ->whenAvailable('@step3', static function (Browser $browser) {
                    // Assert that the code is filled in the input
                    // Change it and test error handling
                    $browser->assertValue('#signup_voucher', 'TEST')
                        ->type('#signup_voucher', 'TESTXX')
                        ->type('#signup_login', 'signuptestdusk')
                        ->type('#signup_password', '123456789')
                        ->type('#signup_password_confirmation', '123456789')
                        ->click('[type=submit]')
                        ->waitFor('#signup_voucher.is-invalid')
                        ->assertVisible('#signup_voucher + .invalid-feedback')
                        ->assertFocused('#signup_voucher')
                        ->assertToast(Toast::TYPE_ERROR, 'Form validation error')
                        // Submit the correct code
                        ->type('#signup_voucher', 'TEST')
                        ->click('[type=submit]');
                })
                ->waitUntilMissing('@step3')
                ->waitUntilMissing('.app-loader')
                ->on(new Dashboard())
                ->assertUser('signuptestdusk@' . \config('app.domain'))
                // Logout the user
                ->within(new Menu(), static function ($browser) {
                    $browser->clickMenuItem('logout');
                });
        });

        $user = $this->getTestUser('signuptestdusk@' . \config('app.domain'));
        $discount = Discount::where('code', 'TEST')->first();
        $this->assertSame($discount->id, $user->wallets()->first()->discount_id);
    }

    /**
     * Test signup with a referral code
     */
    public function testSignupReferralCode(): void
    {
        $referrer = $this->getTestUser('john@kolab.org');
        $program = ReferralProgram::create([
            'name' => "Test Referral",
            'description' => "Test Referral Description",
            'active' => true,
        ]);
        $referral_code = $program->codes()->create(['user_id' => $referrer->id]);

        $this->browse(static function (Browser $browser) use ($referral_code) {
            $browser->visit('/signup/referral/' . $referral_code->code)
                ->onWithoutAssert(new Signup())
                ->waitUntilMissing('.app-loader')
                ->waitFor('@step0')
                ->click('.plan-individual button')
                ->whenAvailable('@step1', static function (Browser $browser) {
                    $browser->type('#signup_first_name', 'Test')
                        ->type('#signup_last_name', 'User')
                        ->type('#signup_email', 'BrowserSignupTestUser1@kolab.org')
                        ->click('[type=submit]');
                })
                ->whenAvailable('@step2', static function (Browser $browser) {
                    $code = SignupCode::orderBy('created_at', 'desc')->first();
                    $browser->type('#signup_short_code', $code->short_code)
                        ->click('[type=submit]');
                })
                ->whenAvailable('@step3', static function (Browser $browser) {
                    $browser->type('#signup_login', 'signuptestdusk')
                        ->type('#signup_password', '123456789')
                        ->type('#signup_password_confirmation', '123456789')
                        ->click('[type=submit]');
                })
                ->waitUntilMissing('@step3')
                ->waitUntilMissing('.app-loader')
                ->on(new Dashboard())
                ->assertUser('signuptestdusk@' . \config('app.domain'))
                // Logout the user
                ->within(new Menu(), static function ($browser) {
                    $browser->clickMenuItem('logout');
                });
        });

        $user = $this->getTestUser('signuptestdusk@' . \config('app.domain'));
        $this->assertSame(1, $referral_code->referrals()->where('user_id', $user->id)->count());
    }

    /**
     * Test signup via invitation link
     */
    public function testSignupInvitation(): void
    {
        // Test non-existing invitation
        $this->browse(static function (Browser $browser) {
            $browser->visit('/signup/invite/TEST')
                ->onWithoutAssert(new Signup())
                ->waitFor('#app > #error-page')
                ->assertErrorPage(404);
        });

        $invitation = SignupInvitation::create(['email' => 'test@domain.org']);

        $this->browse(static function (Browser $browser) use ($invitation) {
            $browser->visit('/signup/invite/' . $invitation->id)
                ->onWithoutAssert(new Signup())
                ->waitUntilMissing('.app-loader')
                ->waitFor('@step0')
                ->click('.plan-individual button')
                ->with('@step3', static function ($step) {
                    $domains_count = count(Domain::getPublicDomains());

                    $step->assertMissing('.card-title')
                        ->waitFor('#signup_last_name')
                        ->assertVisible('#signup_first_name')
                        ->assertVisible('#signup_login')
                        ->assertVisible('#signup_password')
                        ->assertVisible('#signup_password_confirmation')
                        ->assertVisible('select#signup_domain')
                        ->assertElementsCount('select#signup_domain option', $domains_count, false)
                        ->assertVisible('[type=submit]')
                        ->assertVisible('[type=button]') // Back button
                        ->assertSeeIn('[type=submit]', 'Sign Up')
                        ->assertFocused('#signup_first_name')
                        ->assertValue('select#signup_domain', \config('app.domain'))
                        ->assertValue('#signup_first_name', '')
                        ->assertValue('#signup_last_name', '')
                        ->assertValue('#signup_login', '')
                        ->assertValue('#signup_password', '')
                        ->assertValue('#signup_password_confirmation', '');

                    // Submit invalid data
                    $step->type('#signup_login', '*')
                        ->type('#signup_password', '12345678')
                        ->type('#signup_password_confirmation', '123456789')
                        ->click('[type=submit]')
                        ->waitFor('#signup_login.is-invalid')
                        ->assertVisible('#signup_domain + .invalid-feedback')
                        ->assertVisible('#signup_password.is-invalid')
                        ->assertVisible('#signup_password_input .invalid-feedback')
                        ->assertFocused('#signup_login')
                        ->assertToast(Toast::TYPE_ERROR, 'Form validation error');

                    // Submit valid data
                    $step->type('#signup_password_confirmation', '12345678')
                        ->type('#signup_login', 'signuptestdusk')
                        ->type('#signup_first_name', 'First')
                        ->type('#signup_last_name', 'Last')
                        ->click('[type=submit]');
                })
                // At this point we should be auto-logged-in to dashboard
                ->waitUntilMissing('@step3')
                ->waitUntilMissing('.app-loader')
                ->on(new Dashboard())
                ->assertUser('signuptestdusk@' . \config('app.domain'))
                // Logout the user
                ->within(new Menu(), static function ($browser) {
                    $browser->clickMenuItem('logout');
                });
        });

        $invitation->refresh();
        $user = User::where('email', 'signuptestdusk@' . \config('app.domain'))->first();

        $this->assertTrue($invitation->isCompleted());
        $this->assertSame($user->id, $invitation->user_id);
        $this->assertSame('First', $user->getSetting('first_name'));
        $this->assertSame('Last', $user->getSetting('last_name'));
        $this->assertSame($invitation->email, $user->getSetting('external_email'));
    }
}
