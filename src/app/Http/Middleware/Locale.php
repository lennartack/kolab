<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ContentController;
use Illuminate\Http\Request;

class Locale
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        $lang = null;

        // setLocale() will modify the app.locale config entry, so any subsequent
        // request under Swoole would use the changed locale, causing "locale leak"
        // to other users' requests, unless we use env() instead of config() here.
        $default = \env('APP_LOCALE', 'en');

        // Try to get the language from the cookie
        $_lang = self::getLanguageCookie($request);
        if (self::isLocaleAvailable($request, $_lang, $default)) {
            $lang = $_lang;
        }

        // If there's no cookie try the client languages
        if (!$lang) {
            foreach ($request->getLanguages() as $_lang) {
                if (self::isLocaleAvailable($request, $_lang, $default)) {
                    $lang = $_lang;
                    break;
                }
            }
        }

        if (!$lang) {
            $lang = $default;
        }

        if (!app()->isLocale($lang)) {
            app()->setLocale($lang);
        }

        // Allow skins to define/overwrite some localization
        $theme = \config('app.theme');
        \app('translator')->addNamespace('theme', \resource_path("themes/{$theme}/lang"));

        return $next($request);
    }

    protected static function getLanguageCookie(Request $request): ?string
    {
        // Note: $request->cookie() works only with Laravel cookies that are encrypted,
        // that's why we check the header manually
        if (preg_match('/(^|; )language=([a-zA-Z-_]+)/', (string) $request->header('cookie'), $matches)) {
            return $matches[2];
        }

        return null;
    }

    /**
     * Check if the specified language is available
     */
    protected static function isLocaleAvailable(Request $request, &$lang, $default): bool
    {
        if (!is_string($lang) || $lang === '') {
            return false;
        }

        $lang = preg_replace('/[^a-z].*$/', '', strtolower($lang));

        // Always accept the default language without any additional checks
        if ($lang == $default) {
            return true;
        }

        $langDir = resource_path('lang');

        // Allow any existing language for API requests
        if (str_starts_with($request->path(), 'api/')) {
            return file_exists("{$langDir}/{$lang}");
        }

        // Allow languages enabled for UI
        $enabledLanguages = ContentController::locales();
        return in_array($lang, $enabledLanguages) && file_exists("{$langDir}/{$lang}");
    }
}
