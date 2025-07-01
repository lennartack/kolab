<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;

trait SettingsTrait
{
    /**
     * Obtain the value for a setting.
     *
     * Example Usage:
     *
     * ```php
     * $user = User::firstOrCreate(['email' => 'some@other.erg']);
     * $locale = $user->getSetting('locale');
     * ```
     *
     * @param string $key     Setting name
     * @param mixed  $default Default value, to be used if not found
     *
     * @return string|null Setting value
     */
    public function getSetting(string $key, $default = null)
    {
        $setting = $this->settings()->where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Obtain the values for many settings in one go (for better performance).
     *
     * @param array $keys Setting names
     *
     * @return array Setting key=value hash, includes also requested but non-existing settings
     */
    public function getSettings(array $keys): array
    {
        $settings = [];

        foreach ($keys as $key) {
            $settings[$key] = null;
        }

        $this->settings()->whereIn('key', $keys)->get()
            ->each(static function ($setting) use (&$settings) {
                $settings[$setting->key] = $setting->value;
            });

        return $settings;
    }

    /**
     * Remove a setting.
     *
     * Example Usage:
     *
     * ```php
     * $user = User::firstOrCreate(['email' => 'some@other.erg']);
     * $user->removeSetting('locale');
     * ```
     *
     * @param string $key Setting name
     */
    public function removeSetting(string $key): void
    {
        $this->setSetting($key, null);
    }

    /**
     * Remove settings without invoking events.
     *
     * @param array $keys Setting names
     */
    public function removeSettingsQuietly(array $keys): void
    {
        $this->settings()->whereIn('key', $keys)->delete();
    }

    /**
     * Create or update a setting.
     *
     * Example Usage:
     *
     * ```php
     * $user = User::firstOrCreate(['email' => 'some@other.erg']);
     * $user->setSetting('locale', 'en');
     * ```
     *
     * @param string      $key   Setting name
     * @param string|null $value the new value for the setting
     */
    public function setSetting(string $key, $value): void
    {
        $this->storeSetting($key, $value);
    }

    /**
     * Create or update multiple settings in one fell swoop.
     *
     * Example Usage:
     *
     * ```php
     * $user = User::firstOrCreate(['email' => 'some@other.erg']);
     * $user->setSettings(['locale' => 'en', 'country' => 'GB']);
     * ```
     *
     * @param array $data an associative array of key value pairs
     */
    public function setSettings(array $data = []): void
    {
        foreach ($data as $key => $value) {
            $this->storeSetting($key, $value);
        }
    }

    /**
     * Any (additional) properties of this domain.
     *
     * @return HasMany
     */
    public function settings()
    {
        return $this->hasMany(self::class . 'Setting');
    }

    /**
     * Create or update a setting.
     *
     * @param string      $key   Setting name
     * @param string|null $value the new value for the setting
     */
    private function storeSetting(string $key, $value): void
    {
        if ($value === null || $value === '') {
            // Note: We're selecting the record first, so observers can act
            if ($setting = $this->settings()->where('key', $key)->first()) {
                $setting->delete();
            }
        } else {
            // Note: upsert() is a single query (INSERT ... ON DUPLICATE KEY UPDATE),
            // updateOrCreate() is a few queries (BEGIN + INSERT [+ UPDATE] + COMMIT).
            $this->settings()->upsert(
                ['key' => $key, 'value' => $value],
                uniqueBy: ['user_id', 'key', 'value'],
                update: ['key', 'value']
            );
        }
    }
}
