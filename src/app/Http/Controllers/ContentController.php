<?php

namespace App\Http\Controllers;

use App\Utils;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class ContentController extends Controller
{
    /**
     * Get the HTML content for the specified page
     *
     * @param string $page Page template identifier
     *
     * @return View
     */
    public function pageContent(string $page)
    {
        if (empty($page) || !preg_match('/^[a-zA-Z0-9\/]+$/', $page)) {
            abort(404);
        }

        $theme = \config('app.theme');
        $page = mb_strtolower(str_replace('/', '.', $page));

        $file = "themes/{$theme}/pages/{$page}.blade.php";
        $view = "{$theme}.pages.{$page}";

        if (!file_exists(resource_path($file))) {
            abort(404);
        }

        return view($view)->with('env', Utils::uiEnv());
    }

    /**
     * Get the list of FAQ entries for the specified page
     *
     * @param string $page Page path
     */
    public function faqContent(string $page): JsonResponse
    {
        if (empty($page)) {
            return $this->errorResponse(404);
        }

        $page = mb_strtolower($page);
        $faq = [];

        $theme_name = \config('app.theme');
        $theme_file = resource_path("themes/{$theme_name}/theme.json");

        if (file_exists($theme_file)) {
            $theme = json_decode(file_get_contents($theme_file), true);
            if (json_last_error() != \JSON_ERROR_NONE) {
                \Log::error("Failed to parse {$theme_file}: " . json_last_error_msg());
            } elseif (!empty($theme['faq']) && !empty($theme['faq'][$page])) {
                $faq = $theme['faq'][$page];
            }

            // TODO: Support pages with variables, e.g. users/<user-id>
        }

        // Localization
        if (!empty($faq)) {
            foreach ($faq as $idx => $item) {
                if (!empty($item['label'])) {
                    $faq[$idx]['title'] = \trans('theme::faq.' . $item['label']);
                }
            }
        }

        return response()->json(['status' => 'success', 'faq' => $faq]);
    }

    /**
     * Returns list of enabled locales
     *
     * @return array List of two-letter language codes
     */
    public static function locales(): array
    {
        if ($locales = \env('APP_LOCALES')) {
            return preg_split('/\s*,\s*/', strtolower(trim($locales)));
        }

        return ['en', 'de', 'fr'];
    }

    /**
     * Get menu definition from the theme
     */
    public static function menu(): array
    {
        $theme_name = \config('app.theme');
        $theme_file = resource_path("themes/{$theme_name}/theme.json");
        $menu = [];

        if (file_exists($theme_file)) {
            $theme = json_decode(file_get_contents($theme_file), true);

            if (json_last_error() != \JSON_ERROR_NONE) {
                \Log::error("Failed to parse {$theme_file}: " . json_last_error_msg());
            } elseif (!empty($theme['menu'])) {
                $menu = $theme['menu'];
            }
        }

        // TODO: These 2-3 lines could become a utility function somewhere
        $req_domain = preg_replace('/:[0-9]+$/', '', request()->getHttpHost());
        $sys_domain = \config('app.domain');
        $isAdmin = $req_domain == "admin.{$sys_domain}";

        $filter = static function ($item) use ($isAdmin) {
            if ($isAdmin && empty($item['admin'])) {
                return false;
            }
            if (!$isAdmin && !empty($item['admin']) && $item['admin'] === 'only') {
                return false;
            }

            return true;
        };

        $menu = array_values(array_filter($menu, $filter));

        // Load localization files for all supported languages
        $lang_path = resource_path("themes/{$theme_name}/lang");
        $locales = [];
        foreach (self::locales() as $lang) {
            $file = "{$lang_path}/{$lang}/menu.php";
            if (file_exists($file)) {
                $locales[$lang] = include $file;
            }
        }

        foreach ($menu as $idx => $item) {
            // Handle menu localization
            if (!empty($item['label'])) {
                $label = $item['label'];

                foreach ($locales as $lang => $labels) {
                    if (!empty($labels[$label])) {
                        $item["title-{$lang}"] = $labels[$label];
                    }
                }
            }

            // Unset properties that we don't need on the client side
            unset($item['admin']);

            $menu[$idx] = $item;
        }

        return $menu;
    }
}
