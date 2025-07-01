<?php

namespace Tests\Browser\Pages;

use App\Auth\SecondFactor;
use Laravel\Dusk\Page;
use Tests\Browser;

class Home extends Page
{
    /**
     * Get the URL for the page.
     *
     * @return string
     */
    public function url()
    {
        return '/login';
    }

    /**
     * Assert that the browser is on the page.
     *
     * @param \Laravel\Dusk\Browser $browser The browser object
     */
    public function assert($browser)
    {
        $browser->waitForLocation($this->url())
            ->waitUntilMissing('.app-loader')
            ->assertVisible('form.form-signin');
    }

    /**
     * Get the element shortcuts for the page.
     *
     * @return array
     */
    public function elements()
    {
        return [
            '@app' => '#app',
            '@email-input' => '#email',
            '@password-input' => '#password',
            '@second-factor-input' => '#secondfactor',
            '@logon-form' => '#logon-form',
            '@logon-button' => '#logon-form button.btn-primary',
            '@new-password-input' => '#new_password',
            '@new-password-confirmation-input' => '#new_password_confirmation',
        ];
    }

    /**
     * Submit logon form.
     *
     * @param Browser $browser            The browser object
     * @param string  $username           User name
     * @param string  $password           User password
     * @param bool    $wait_for_dashboard
     * @param array   $config             Client-site config
     */
    public function submitLogon(
        $browser,
        $username,
        $password,
        $wait_for_dashboard = false,
        $config = []
    ) {
        $browser->clearToasts()
            ->assertMissing('@new-password-input')
            ->assertMissing('@new-password-confirmation-input')
            ->assertMissing('@logon-form p.alert')
            ->type('@email-input', $username)
            ->type('@password-input', $password);

        if ($username == 'ned@kolab.org') {
            $code = SecondFactor::code('ned@kolab.org');
            $browser->type('@second-factor-input', $code);
        }

        if (!empty($config)) {
            $browser->script(
                sprintf('Object.assign(window.config, %s)', \json_encode($config))
            );
        }

        $browser->press('@logon-button');

        if ($wait_for_dashboard) {
            $browser->waitForLocation('/dashboard');
        }
    }
}
