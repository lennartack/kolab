<?php

namespace Tests;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Assert;
use Tests\Browser\Components\Error;
use Tests\Browser\Components\Toast;

/**
 * Laravel Dusk Browser extensions
 */
class Browser extends \Laravel\Dusk\Browser
{
    /**
     * Assert that the given element attribute contains specified text.
     */
    public function assertAttributeRegExp($selector, $attribute, $regexp)
    {
        $element = $this->resolver->findOrFail($selector);
        $value = (string) $element->getAttribute($attribute);
        $error = "No expected text in [{$selector}][{$attribute}]. Found: {$value}";

        Assert::assertMatchesRegularExpression($regexp, $value, $error);

        return $this;
    }

    /**
     * Assert number of (visible) elements
     */
    public function assertElementsCount($selector, $expected_count, $visible = true)
    {
        $elements = $this->elements($selector);
        $count = count($elements);

        if ($visible) {
            foreach ($elements as $element) {
                if (!$element->isDisplayed()) {
                    $count--;
                }
            }
        }

        Assert::assertEquals($expected_count, $count, "Count of [{$selector}] elements is not {$expected_count}");

        return $this;
    }

    /**
     * Assert Tip element content
     */
    public function assertTip($selector, $content)
    {
        return $this->click($selector)
            ->withinBody(static function ($browser) use ($content) {
                $browser->waitFor('div.tooltip .tooltip-inner')
                    ->assertSeeIn('div.tooltip .tooltip-inner', $content);
            })
            ->click($selector);
    }

    /**
     * Assert Toast element content (and close it)
     */
    public function assertToast(string $type, string $message, $title = null)
    {
        return $this->withinBody(static function ($browser) use ($type, $title, $message) {
            $browser->with(new Toast($type), static function (Browser $browser) use ($title, $message) {
                $browser->assertToastTitle($title)
                    ->assertToastMessage($message)
                    ->closeToast();
            });
        });
    }

    /**
     * Assert specified error page is displayed.
     */
    public function assertErrorPage(int $error_code, string $hint = '')
    {
        $this->with(new Error($error_code, $hint), static function ($browser) {
            // empty, assertions will be made by the Error component itself
        });

        return $this;
    }

    /**
     * Assert that the given element has specified class assigned.
     */
    public function assertHasClass($selector, $class_name)
    {
        $element = $this->resolver->findOrFail($selector);
        $classes = explode(' ', (string) $element->getAttribute('class'));

        Assert::assertContains($class_name, $classes, "[{$selector}] has no class '{$class_name}'");

        return $this;
    }

    /**
     * Assert that the given element is readonly
     */
    public function assertReadonly($selector)
    {
        $element = $this->resolver->findOrFail($selector);
        $value = $element->getAttribute('readonly');

        Assert::assertTrue($value == 'true', "Element [{$selector}] is not readonly");

        return $this;
    }

    /**
     * Assert that the given element is not readonly
     */
    public function assertNotReadonly($selector)
    {
        $element = $this->resolver->findOrFail($selector);
        $value = $element->getAttribute('readonly');

        Assert::assertTrue($value != 'true', "Element [{$selector}] is not readonly");

        return $this;
    }

    /**
     * Assert that the given element contains specified text,
     * no matter it's displayed or not.
     */
    public function assertText($selector, $text)
    {
        $element = $this->resolver->findOrFail($selector);

        if ($text === '') {
            Assert::assertTrue((string) $element->getText() === $text, "Element's text is not empty [{$selector}]");
        } else {
            Assert::assertTrue(str_contains($element->getText(), $text), "No expected text in [{$selector}]");
        }

        return $this;
    }

    /**
     * Assert that the given element contains specified text,
     * no matter it's displayed or not - using a regular expression.
     */
    public function assertTextRegExp($selector, $regexp)
    {
        $element = $this->resolver->findOrFail($selector);

        Assert::assertMatchesRegularExpression($regexp, $element->getText(), "No expected text in [{$selector}]");

        return $this;
    }

    /**
     * Remove all toast messages
     */
    public function clearToasts()
    {
        $this->script("\$('.toast-container > *').remove()");

        return $this;
    }

    /**
     * Wait until a button becomes enabled and click it
     */
    public function clickWhenEnabled($selector)
    {
        return $this->waitFor($selector . ':not([disabled])')->click($selector);
    }

    /**
     * Execute javascript code ignoring it's result
     */
    public function execScript($script)
    {
        $this->script($script);
        return $this;
    }

    /**
     * Check if in Phone mode
     */
    public static function isPhone()
    {
        return getenv('TESTS_MODE') == 'phone';
    }

    /**
     * Check if in Tablet mode
     */
    public static function isTablet()
    {
        return getenv('TESTS_MODE') == 'tablet';
    }

    /**
     * Check if in Desktop mode
     */
    public static function isDesktop()
    {
        return !self::isPhone() && !self::isTablet();
    }

    /**
     * Returns content of a downloaded file
     */
    public function readDownloadedFile($filename, $sleep = 5)
    {
        $filename = __DIR__ . "/Browser/downloads/{$filename}";

        // Give the browser a chance to finish download
        // Note: For unknown reason Chromium would create files with added underscore
        if (!file_exists($filename) && !file_exists("{$filename}_") && $sleep) {
            sleep($sleep);
        }

        if (file_exists($filename)) {
            return file_get_contents($filename);
        }
        if (file_exists("{$filename}_")) {
            return file_get_contents("{$filename}_");
        }

        Assert::assertFileExists($filename);
    }

    /**
     * Removes downloaded file
     */
    public function removeDownloadedFile($filename)
    {
        @unlink(__DIR__ . "/Browser/downloads/{$filename}");
        @unlink(__DIR__ . "/Browser/downloads/{$filename}_"); // see readDownloadedFile() method

        return $this;
    }

    /**
     * Clears the input field and related vue v-model data.
     */
    public function vueClear($selector)
    {
        $selector = $this->resolver->format($selector);

        // The existing clear(), and type() with empty string do not work.
        // We have to clear the field and dispatch 'input' event programatically.

        $this->script(
            "var element = document.querySelector('{$selector}');"
            . "element.value = '';"
            . "element.dispatchEvent(new Event('input'))"
        );

        return $this;
    }

    /**
     * Execute code within body context.
     * Useful to execute code that selects elements outside of a component context
     */
    public function withinBody($callback)
    {
        if ($this->resolver->prefix != 'body') {
            $orig_prefix = $this->resolver->prefix;
            $this->resolver->prefix = 'body';
        }

        call_user_func($callback, $this);

        if (isset($orig_prefix)) {
            $this->resolver->prefix = $orig_prefix;
        }

        return $this;
    }

    /**
     * Store the console output with the given name. Overwrites Dusk's method.
     *
     * @param string $name
     *
     * @return $this
     */
    public function storeConsoleLog($name)
    {
        if (in_array($this->driver->getCapabilities()->getBrowserName(), static::$supportsRemoteLogs)) {
            $console = $this->driver->manage()->getLog('browser');

            // Ignore errors/warnings irrelevant for testing
            foreach ($console as $idx => $entry) {
                if (
                    $entry['level'] != 'SEVERE'
                    || strpos($entry['message'], 'Failed to load resource: the server responded with a status of')
                    || preg_match('/Uncaught .?: Request failed with status code 422/', $entry['message'])
                    || preg_match('/^\S+\.js [0-9]+:[0-9]+\s*$/', $entry['message'])
                ) {
                    $console[$idx] = null;
                }
            }

            $console = array_values(array_filter($console));

            if (!empty($console)) {
                $file = sprintf('%s/%s.log', rtrim(static::$storeConsoleLogAt, '/'), $name);
                $content = json_encode($console, \JSON_PRETTY_PRINT);

                file_put_contents($file, $content);
            }
        }

        return $this;
    }

    /**
     * Store custom config values in the cache to be picked up in the DevelConfig middleware on the next request.
     *
     * This allows to propagte custom config values to the server that interacts with the browser.
     *
     * @return $this
     */
    public function withConfig(array $config)
    {
        Cache::put('duskconfig', json_encode($config));
        return $this;
    }
}
