<?php

namespace App;

use App\Http\Controllers\ContentController;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Small utility functions for App.
 */
class Utils
{
    // Note: Removed '0', 'O', '1', 'I' as problematic with some fonts
    public const CHARS = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /**
     * Exchange rates for unit tests
     */
    private static $testRates;

    /**
     * Count the number of lines in a file.
     *
     * Useful for progress bars.
     *
     * @param string $file the filepath to count the lines of
     *
     * @return int
     */
    public static function countLines($file)
    {
        $fh = fopen($file, 'r');
        $numLines = 0;

        while (!feof($fh)) {
            $numLines += substr_count(fread($fh, 8192), "\n");
        }

        fclose($fh);

        return $numLines;
    }

    /**
     * Return the country ISO code for an IP address.
     *
     * @param string $ip       IP address
     * @param string $fallback Fallback country code
     */
    public static function countryForIP($ip, $fallback = 'CH'): string
    {
        if (!str_contains($ip, ':')) {
            // Skip the query if private network
            if (str_starts_with($ip, '127.')) {
                $net = null;
            } else {
                $net = IP4Net::getNet($ip);
            }
        } else {
            $net = IP6Net::getNet($ip);
        }

        return $net && $net->country ? $net->country : $fallback;
    }

    /**
     * Return the country ISO code for the current request.
     */
    public static function countryForRequest()
    {
        $request = \request();
        $ip = $request->ip();

        return self::countryForIP($ip);
    }

    /**
     * Return the number of days in the month prior to this one.
     *
     * @return int
     */
    public static function daysInLastMonth()
    {
        $start = new Carbon('first day of last month');
        $end = new Carbon('last day of last month');

        return (int) $start->diffInDays($end) + 1;
    }

    /**
     * Default route handler
     */
    public static function defaultView()
    {
        // Return standard empty 404 response for non-existing resources and API routes
        // TODO: Is there a better way? we'd need access to the vue-router routes here.
        if (preg_match('~^(api|themes|js|vendor)/~', request()->path())) {
            return response('', 404);
        }

        $env = self::uiEnv();
        return view($env['view'])->with('env', $env);
    }

    /**
     * Download a file from the interwebz and store it locally.
     *
     * @param string $source The source location
     * @param string $target The target location
     * @param bool   $force  Force the download (and overwrite target)
     *
     * @throws \Exception
     */
    public static function downloadFile($source, $target, $force = false): void
    {
        if (is_file($target) && !$force) {
            return;
        }

        \Log::info("Retrieving {$source}");

        Http::sink($target)->get($source)->throwUnlessStatus(200);
    }

    /**
     * Converts an email address to lower case. Keeps the LMTP shared folder
     * addresses character case intact.
     *
     * @param string $email Email address
     *
     * @return string Email address
     */
    public static function emailToLower(string $email): string
    {
        // For LMTP shared folder address lower case the domain part only
        if (str_starts_with($email, 'shared+shared/')) {
            $pos = strrpos($email, '@');
            $domain = substr($email, $pos + 1);
            $local = substr($email, 0, strlen($email) - strlen($domain) - 1);

            return $local . '@' . strtolower($domain);
        }

        return strtolower($email);
    }

    /**
     * Make sure that IMAP folder access rights contains "anyone: p" permission
     *
     * @param array $acl ACL (in form of "user, permission" records)
     *
     * @return array ACL list
     */
    public static function ensureAclPostPermission(array $acl): array
    {
        foreach ($acl as $idx => $entry) {
            if (str_starts_with($entry, 'anyone,')) {
                if (strpos($entry, 'read-only')) {
                    $acl[$idx] = 'anyone, lrsp';
                } elseif (strpos($entry, 'read-write')) {
                    $acl[$idx] = 'anyone, lrswitednp';
                }

                return $acl;
            }
        }

        $acl[] = 'anyone, p';

        return $acl;
    }

    /**
     * Generate a passphrase. Not intended for use in production, so limited to environments that are not production.
     *
     * @return string
     */
    public static function generatePassphrase()
    {
        if (\config('app.env') != 'production') {
            if (\config('app.passphrase')) {
                return \config('app.passphrase');
            }
        }

        $alphaLow = 'abcdefghijklmnopqrstuvwxyz';
        $alphaUp = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $num = '0123456789';
        $stdSpecial = '~`!@#$%^&*()-_+=[{]}\|\'";:/?.>,<';

        $source = $alphaLow . $alphaUp . $num . $stdSpecial;

        $result = '';

        for ($x = 0; $x < 16; $x++) {
            $result .= substr($source, random_int(0, strlen($source) - 1), 1);
        }

        return $result;
    }

    /**
     * Retrieve the network ID and Type from a client address
     *
     * @param string $clientAddress the IPv4 or IPv6 address
     *
     * @return array an array of ID and class or null and null
     */
    public static function getNetFromAddress($clientAddress)
    {
        if (!str_contains($clientAddress, ':')) {
            $net = IP4Net::getNet($clientAddress);

            if ($net) {
                return [$net->id, IP4Net::class];
            }
        } else {
            $net = IP6Net::getNet($clientAddress);

            if ($net) {
                return [$net->id, IP6Net::class];
            }
        }

        return [null, null];
    }

    /**
     * Calculate the broadcast address provided a net number and a prefix.
     *
     * @param string $net    a valid IPv6 network number
     * @param int    $prefix the network prefix
     *
     * @return string
     */
    public static function ip6Broadcast($net, $prefix)
    {
        $netHex = bin2hex(inet_pton($net));

        // Overwriting first address string to make sure notation is optimal
        $net = inet_ntop(hex2bin($netHex));

        // Calculate the number of 'flexible' bits
        $flexbits = 128 - $prefix;

        // Build the hexadecimal string of the last address
        $lastAddrHex = $netHex;

        // We start at the end of the string (which is always 32 characters long)
        $pos = 31;
        while ($flexbits > 0) {
            // Get the character at this position
            $orig = substr($lastAddrHex, $pos, 1);

            // Convert it to an integer
            $origval = hexdec($orig);

            // OR it with (2^flexbits)-1, with flexbits limited to 4 at a time
            $newval = $origval | (2 ** min(4, $flexbits) - 1);

            // Convert it back to a hexadecimal character
            $new = dechex($newval);

            // And put that character back in the string
            $lastAddrHex = substr_replace($lastAddrHex, $new, $pos, 1);

            // We processed one nibble, move to previous position
            $flexbits -= 4;
            $pos--;
        }

        // Convert the hexadecimal string to a binary string
        $lastaddrbin = hex2bin($lastAddrHex);

        // And create an IPv6 address from the binary string
        $lastaddrstr = inet_ntop($lastaddrbin);

        return $lastaddrstr;
    }

    /**
     * Checks that a model is soft-deletable
     *
     * @param mixed $model Model object or a class name
     */
    public static function isSoftDeletable($model): bool
    {
        if (is_string($model) && !class_exists($model)) {
            return false;
        }

        return method_exists($model, 'restore');
    }

    /**
     * Normalize an email address.
     *
     * This means to lowercase and strip components separated with recipient delimiters.
     *
     * @param ?string $address The address to normalize
     * @param bool    $asArray Return an array with local and domain part
     *
     * @return string|array Normalized email address as string or array
     */
    public static function normalizeAddress(?string $address, bool $asArray = false)
    {
        if ($address === null || $address === '') {
            return $asArray ? ['', ''] : '';
        }

        $address = self::emailToLower($address);

        if (!str_contains($address, '@')) {
            return $asArray ? [$address, ''] : $address;
        }

        [$local, $domain] = explode('@', $address);

        if (str_contains($local, '+')) {
            $local = explode('+', $local)[0];
        }

        return $asArray ? [$local, $domain] : "{$local}@{$domain}";
    }

    /**
     * Returns the current user's email address or null.
     */
    public static function userEmailOrNull(): ?string
    {
        $user = Auth::user();

        if (!$user) {
            return null;
        }

        return $user->email;
    }

    /**
     * Returns a random string consisting of a quantity of segments of a certain length joined.
     *
     * Example:
     *
     * ```php
     * $roomName = strtolower(\App\Utils::randStr(3, 3, '-');
     * // $roomName == '3qb-7cs-cjj'
     * ```
     *
     * @param int    $length The length of each segment
     * @param int    $qty    The quantity of segments
     * @param string $join   The string to use to join the segments
     * @param string $chars  The characters to use to build the code
     *
     * @return string
     */
    public static function randStr($length, $qty = 1, $join = '', string $chars = '')
    {
        if (strlen($chars) == 0) {
            $chars = env('SHORTCODE_CHARS', self::CHARS);
        }

        $randStrs = [];

        for ($x = 0; $x < $qty; $x++) {
            $string = [];

            for ($y = 0; $y < $length; $y++) {
                $string[] = $chars[random_int(0, strlen($chars) - 1)];
            }

            shuffle($string);

            $randStrs[$x] = implode('', $string);
        }

        return implode($join, $randStrs);
    }

    /**
     * Returns a UUID in the form of an integer.
     */
    public static function uuidInt(): int
    {
        $hex = self::uuidStr();
        $bin = pack('h*', str_replace('-', '', $hex));
        $ids = unpack('L', $bin);
        $id = array_shift($ids);

        return $id;
    }

    /**
     * Returns a UUID in the form of a string.
     */
    public static function uuidStr(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Create self URL
     *
     * @param string   $route    Route/Path/URL
     * @param int|null $tenantId Current tenant
     *
     * @todo Move this to App\Http\Controllers\Controller
     *
     * @return string Full URL
     */
    public static function serviceUrl(string $route, $tenantId = null): string
    {
        if (preg_match('|^https?://|i', $route)) {
            return $route;
        }

        $url = Tenant::getConfig($tenantId, 'app.public_url');

        if (!$url) {
            $url = Tenant::getConfig($tenantId, 'app.url');
        }

        return rtrim(trim($url, '/') . '/' . ltrim($route, '/'), '/');
    }

    /**
     * Create a configuration/environment data to be passed to
     * the UI
     *
     * @todo Move this to App\Http\Controllers\Controller
     *
     * @return array Configuration data
     */
    public static function uiEnv(): array
    {
        $countries = include resource_path('countries.php');
        $req_domain = preg_replace('/:[0-9]+$/', '', request()->getHttpHost());
        $sys_domain = \config('app.domain');
        $opts = [
            'app.name',
            'app.url',
            'app.domain',
            'app.theme',
            'app.webmail_url',
            'app.support_email',
            'app.company.copyright',
            'app.companion_download_link',
            'app.shared_folder_types',
            'app.with_signup',
            'app.with_user_search',
            'mail.from.address',
        ];

        $env = \app('config')->getMany($opts);

        $env['countries'] = $countries ?: [];
        $env['view'] = 'root';
        $env['jsapp'] = 'user.js';

        if ($req_domain == "admin.{$sys_domain}") {
            $env['jsapp'] = 'admin.js';
        } elseif ($req_domain == "reseller.{$sys_domain}") {
            $env['jsapp'] = 'reseller.js';
        }

        $env['paymentProvider'] = \config('services.payment_provider');
        $env['stripePK'] = \config('services.stripe.public_key');
        $env['maxChunkSize'] = \App\Backends\Storage::maxChunkSize();
        $env['languages'] = ContentController::locales();
        $env['menu'] = ContentController::menu();

        return $env;
    }

    /**
     * Set test exchange rates.
     *
     * @param array $rates: Exchange rates
     */
    public static function setTestExchangeRates(array $rates): void
    {
        self::$testRates = $rates;
    }

    /**
     * Retrieve an exchange rate.
     *
     * @param string $sourceCurrency: Currency from which to convert
     * @param string $targetCurrency: Currency to convert to
     *
     * @return float Exchange rate
     */
    public static function exchangeRate(string $sourceCurrency, string $targetCurrency): float
    {
        if (strcasecmp($sourceCurrency, $targetCurrency) == 0) {
            return 1.0;
        }

        if (isset(self::$testRates[$targetCurrency])) {
            return (float) self::$testRates[$targetCurrency];
        }

        $currencyFile = resource_path("exchangerates-{$sourceCurrency}.php");

        // Attempt to find the reverse exchange rate, if we don't have the file for the source currency
        if (!file_exists($currencyFile)) {
            $rates = include resource_path("exchangerates-{$targetCurrency}.php");
            if (!isset($rates[$sourceCurrency])) {
                throw new \Exception("Failed to find the reverse exchange rate for " . $sourceCurrency);
            }
            return 1.0 / (float) $rates[$sourceCurrency];
        }

        $rates = include $currencyFile;
        if (!isset($rates[$targetCurrency])) {
            throw new \Exception("Failed to find exchange rate for " . $targetCurrency);
        }

        return (float) $rates[$targetCurrency];
    }

    /**
     * A helper to display human-readable amount of money using
     * for specified currency and locale.
     *
     * @param int    $amount   Amount of money (in cents)
     * @param string $currency Currency code
     * @param string $locale   Output locale
     *
     * @return string String representation, e.g. "9.99 CHF"
     */
    public static function money(int $amount, $currency, $locale = 'de_DE'): string
    {
        $nf = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $result = $nf->formatCurrency(round($amount / 100, 2), $currency);

        // Replace non-breaking space
        return str_replace("\xC2\xA0", " ", $result);
    }

    /**
     * A helper to display human-readable percent value
     * for specified currency and locale.
     *
     * @param int|float $percent Percent value (0 to 100)
     * @param string    $locale  Output locale
     *
     * @return string String representation, e.g. "0 %", "7.7 %"
     */
    public static function percent(float|int $percent, $locale = 'de_DE'): string
    {
        $nf = new \NumberFormatter($locale, \NumberFormatter::PERCENT);
        $sep = $nf->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);

        $result = sprintf('%.2F', $percent);
        $result = preg_replace('/\.00/', '', $result);
        $result = preg_replace('/(\.[0-9])0/', '\1', $result);
        $result = str_replace('.', $sep, $result);

        return $result . ' %';
    }
}
