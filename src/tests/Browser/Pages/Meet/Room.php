<?php

namespace Tests\Browser\Pages\Meet;

use App\Auth\SecondFactor;
use Laravel\Dusk\Page;
use PHPUnit\Framework\Assert;
use Tests\Browser;

class Room extends Page
{
    public const BUTTON_ACTIVE = 1;
    public const BUTTON_ENABLED = 2;
    public const BUTTON_INACTIVE = 4;
    public const BUTTON_DISABLED = 8;

    public const ICO_MODERATOR = 'moderator';
    public const ICO_USER = 'user';
    public const ICO_INTERPRETER = 'interpreter';

    protected $roomName;

    /**
     * Object constructor.
     *
     * @param string $name Room name
     */
    public function __construct($name)
    {
        $this->roomName = $name;
    }

    /**
     * Get the URL for the page.
     *
     * @return string
     */
    public function url()
    {
        return '/meet/' . $this->roomName;
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
            ->waitUntilMissing('#meet-setup div.status-message.loading');
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

            '@setup-form' => '#meet-setup form',
            '@setup-title' => '#meet-setup .card-title',
            '@setup-mic-select' => '#setup-microphone',
            '@setup-cam-select' => '#setup-camera',
            '@setup-nickname-input' => '#setup-nickname',
            '@setup-password-input' => '#setup-password',
            '@setup-preview' => '#meet-setup .media-setup-preview',
            '@setup-volume' => '#meet-setup .media-setup-preview .volume',
            '@setup-video' => '#meet-setup .media-setup-preview video',
            '@setup-status-message' => '#meet-setup div.status-message',
            '@setup-button' => '#join-button',

            '@toolbar' => '#meet-session-toolbar',

            '@menu' => '#meet-session-menu',

            '@counter' => '#meet-counter',

            '@session' => '#meet-session',
            '@subscribers' => '#meet-subscribers',
            '@queue' => '#meet-queue',

            '@chat' => '#meet-chat',
            '@chat-input' => '#meet-chat textarea',
            '@chat-list' => '#meet-chat .chat',

            '@login-form' => '#meet-auth',
            '@login-email-input' => '#email',
            '@login-password-input' => '#password',
            '@login-second-factor-input' => '#secondfactor',
            '@login-button' => '#meet-auth button',
        ];
    }

    /**
     * Assert menu state.
     *
     * @param Browser $browser The browser object
     * @param array   $menu    Menu items/state
     */
    public function assertToolbar($browser, array $menu): void
    {
        $browser->assertElementsCount('@menu button', count($menu));

        foreach ($menu as $item => $state) {
            $this->assertToolbarButtonState($browser, $item, $state);
        }
    }

    /**
     * Assert menu button state.
     *
     * @param Browser $browser The browser object
     * @param string  $button  Button name
     * @param int     $state   Expected button state (sum of BUTTON_* consts)
     */
    public function assertToolbarButtonState($browser, $button, $state): void
    {
        $class = '';

        if ($state & self::BUTTON_ACTIVE) {
            $class .= '.on';
        }

        if ($state & self::BUTTON_INACTIVE) {
            $class .= ':not(.on)';
        }

        if ($state & self::BUTTON_DISABLED) {
            $class .= '[disabled]';
        }

        if ($state & self::BUTTON_ENABLED) {
            $class .= ':not([disabled])';
        }

        $browser->waitFor('@menu button.link-' . $button . $class, 2);
    }

    /**
     * Assert the <video> element's 'muted' property state
     *
     * @param Browser $browser  The browser object
     * @param string  $selector Video element selector
     * @param bool    $state    Expected state
     */
    public function assertAudioMuted($browser, $selector, $state): void
    {
        $selector = addslashes($browser->resolver->format($selector));

        $result = $browser->script(
            "var video = document.querySelector('{$selector}'); return video.muted"
        );

        Assert::assertSame((bool) $result[0], $state);
    }

    /**
     * Assert the participant icon type
     *
     * @param Browser $browser  The browser object
     * @param string  $selector Element selector
     * @param string  $type     Participant icon type
     */
    public function assertUserIcon($browser, $selector, $type): void
    {
        $browser->assertVisible("{$selector} svg.{$type}")
            ->assertMissing("{$selector} svg:not(.{$type})");
    }

    /**
     * Set the nickname for the participant
     *
     * @param Browser $browser  The browser object
     * @param string  $selector Participant element selector
     * @param string  $nickname Nickname
     */
    public function setNickname($browser, $selector, $nickname): void
    {
        $element = "{$selector} .meet-nickname .content";

        $browser->click("{$selector} .meet-nickname")
            ->waitFor("{$selector} .dropdown-menu")
            ->assertSeeIn("{$selector} .dropdown-menu > .action-nickname", 'Nickname')
            ->click("{$selector} .dropdown-menu > .action-nickname")
            ->waitUntilMissing('.dropdown-menu')
            // Use script() because type() does not work with this contenteditable widget
            ->script(
                "var element = document.querySelector('{$element}');"
                . "element.innerText = '{$nickname}';"
                . "element.dispatchEvent(new KeyboardEvent('keydown', { keyCode: 27 }))"
            );
    }

    /**
     * Submit logon form.
     *
     * @param Browser $browser  The browser object
     * @param string  $username User name
     * @param string  $password User password
     * @param array   $config   Client-site config
     */
    public function submitLogon($browser, $username, $password, $config = []): void
    {
        $browser->type('@login-email-input', $username)
            ->type('@login-password-input', $password);

        if ($username == 'ned@kolab.org') {
            $code = SecondFactor::code('ned@kolab.org');
            $browser->type('@login-second-factor-input', $code);
        }

        if (!empty($config)) {
            $browser->script(
                sprintf('Object.assign(window.config, %s)', \json_encode($config))
            );
        }

        $browser->click('@login-button');
    }
}
