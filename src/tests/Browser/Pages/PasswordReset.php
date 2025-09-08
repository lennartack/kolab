<?php

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;
use Laravel\Dusk\Page;

class PasswordReset extends Page
{
    /**
     * Get the URL for the page.
     */
    public function url(): string
    {
        return '/password-reset';
    }

    /**
     * Assert that the browser is on the page.
     *
     * @param Browser $browser The browser object
     */
    public function assert($browser)
    {
        $browser->assertPathBeginsWith('/password-reset')
            ->waitUntilMissing('.app-loader');
    }

    /**
     * Get the element shortcuts for the page.
     */
    public function elements(): array
    {
        return [
            '@app' => '#app',
            '@step1' => '#step1',
            '@step2' => '#step2',
            '@step3' => '#step3',
        ];
    }
}
