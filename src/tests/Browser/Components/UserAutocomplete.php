<?php

namespace Tests\Browser\Components;

use Laravel\Dusk\Component as BaseComponent;
use Tests\Browser;

class UserAutocomplete extends BaseComponent
{
    protected $selector;

    public function __construct($selector)
    {
        $this->selector = $selector;
    }

    /**
     * Get the root selector for the component.
     *
     * @return string
     */
    public function selector()
    {
        return $this->selector;
    }

    /**
     * Assert that the browser page contains the component.
     *
     * @param Browser $browser
     */
    public function assert($browser)
    {
        $browser->assertAttributeRegExp($this->selector, 'autocomplete', '/^off$/')
            ->assertAttributeRegExp($this->selector, 'list', '/.*-datalist$/');
    }

    /**
     * Assert list
     *
     * @param Browser               $browser
     * @param array<string, string> $users   List of user labels indexed by email address
     */
    public function assertAutocompleteList($browser, $users)
    {
        if (empty($users)) {
            $browser->waitUntilMissing("{$this->selector}-datalist > option");
        } else {
            $browser->waitFor("{$this->selector}-datalist > option")
                ->assertElementsCount("{$this->selector}-datalist > option", count($users));

            $idx = 0;
            foreach ($users as $email => $user) {
                $idx++;
                $selector = "{$this->selector}-datalist > option:nth-child({$idx})";
                $browser->assertSeeIn($selector, $user)
                    ->assertAttributeRegExp($selector, 'value', '/^' . preg_quote($email, '/') . '$/');
            }
        }
    }

    /**
     * Enter text into the input
     *
     * @param Browser $browser
     * @param string  $text
     */
    public function autocomplete($browser, $text)
    {
        $browser->keys($this->selector, str_split($text));
    }

    /**
     * Select a user
     *
     * @param Browser $browser
     * @param string  $email   Email address to select
     */
    public function selectAutocompleteUser($browser, $email)
    {
        $browser->click("{$this->selector}-datalist > option[value=\"{$email}\"]")
            ->waitUntilMissing("{$this->selector}-datalist > option")
            ->assertValue($this->selector, $email);
    }
}
