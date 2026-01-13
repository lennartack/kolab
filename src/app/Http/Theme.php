<?php

namespace App\Http;

class Theme
{
    protected $theme;
    protected $meta = [];

    public function __construct()
    {
        $this->theme = \config('app.theme');

        $theme_file = resource_path("themes/{$this->theme}/theme.json");

        if (file_exists($theme_file)) {
            $this->meta = json_decode(file_get_contents($theme_file), true);

            if (json_last_error() != \JSON_ERROR_NONE) {
                \Log::error("Failed to parse {$theme_file}: " . json_last_error_msg());
                $this->meta = [];
            }
        }
    }

    /**
     * Get FAQ entries from the theme
     *
     * @param string $page Page name
     */
    public function faq(string $page): array
    {
        $page = mb_strtolower(str_replace('/', '.', $page));

        return $this->meta['faq'][$page] ?? [];
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
    public function menu(): array
    {
        // TODO: These 2-3 lines could become a utility function somewhere
        $req_domain = preg_replace('/:[0-9]+$/', '', \request()->getHttpHost());
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

        $menu = array_values(array_filter($this->meta['menu'] ?? [], $filter));

        // Load localization files for all supported languages
        $lang_path = resource_path("themes/{$this->theme}/lang");
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

    /**
     * Get HTML <meta> definition from the theme
     */
    public function meta(): array
    {
        return $this->meta['meta'] ?? [];
    }

    /**
     * Get theme view name for a specified page (if exists)
     *
     * @param string $page Page name
     */
    public function pageView(string $page): ?string
    {
        $page = mb_strtolower(str_replace('/', '.', $page));
        $file = resource_path("themes/{$this->theme}/pages/{$page}.blade.php");

        if (!file_exists($file)) {
            return null;
        }

        return "{$this->theme}.pages.{$page}";
    }
}
