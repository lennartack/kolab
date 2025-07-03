<?php

namespace App\Policy;

use App\Domain;
use App\Resource;
use App\SharedFolder;
use App\User;
use App\Utils as AppUtils;

class Utils
{
    /**
     * Find objects that are the recipient for the specified email address.
     *
     * @param string $address Email address
     */
    public static function findObjectsByRecipientAddress($address): array
    {
        [$local, $domainName] = AppUtils::normalizeAddress($address, true);

        if (empty($domainName)) {
            return [];
        }

        $address = $local . '@' . $domainName;

        $domain = Domain::where('namespace', $domainName)->first();

        if (!$domain) {
            return [];
        }

        // Find user/shared-folder/resource with specified address
        // FIXME: Groups (distribution lists) also have an email address,
        // but they aren't mailrecipients, or are they?

        $user = User::where('email', $address)->first();

        if ($user) {
            return [$user];
        }

        $folder = SharedFolder::where('email', $address)->first();

        if ($folder) {
            return [$folder];
        }

        $resource = Resource::where('email', $address)->first();

        if ($resource) {
            return [$resource];
        }

        // Find aliases with specified address
        // FIXME: Folders and users can share aliases, should we merge the result (and return both)?

        $users = User::select('users.*')->distinct()
            ->join('user_aliases', 'user_aliases.user_id', '=', 'users.id')
            ->where('alias', $address)
            ->get();

        if (count($users) > 0) {
            return $users->all();
        }

        $folders = SharedFolder::select('shared_folders.*')->distinct()
            ->join('shared_folder_aliases', 'shared_folder_aliases.shared_folder_id', '=', 'shared_folders.id')
            ->where('alias', $address)
            ->get();

        if (count($folders) > 0) {
            return $folders->all();
        }

        // Use catchall@ alias if exists
        // FIXME: Folders and users can share aliases, should we merge the result (and return both)?

        $users = User::select('users.*')->distinct()
            ->join('user_aliases', 'user_aliases.user_id', '=', 'users.id')
            ->where('alias', "catchall@{$domain->namespace}")
            ->get();

        if (count($users) > 0) {
            return $users->all();
        }

        $folders = SharedFolder::select('shared_folders.*')->distinct()
            ->join('shared_folder_aliases', 'shared_folder_aliases.shared_folder_id', '=', 'shared_folders.id')
            ->where('alias', "catchall@{$domain->namespace}")
            ->get();

        if (count($folders) > 0) {
            return $folders->all();
        }

        return [];
    }

    /**
     * Get user setting with a fallback to account policy
     *
     * @param User   $user User to get the setting for
     * @param string $name Setting name
     */
    public static function getPolicySetting(User $user, $name): bool|string
    {
        // Fallback default values for policies
        // TODO: This probably should be configurable
        $defaults = [
            'greylist_policy' => true,
        ];

        $policy_name = str_replace(['_enabled', '_config'], '_policy', $name);
        $settings = $user->getSettings([$name, $policy_name]);

        $value = $settings[$name] ?? null;

        if ($value === null) {
            $owner = $user->walletOwner();

            if ($owner && $owner->id != $user->id) {
                $value = $owner->getSetting($policy_name);
            } elseif (isset($settings[$policy_name])) {
                $value = $settings[$policy_name];
            }
        }

        if ($value === null && isset($defaults[$policy_name])) {
            return $defaults[$policy_name];
        }

        // For now it's only bool settings, but it might be something else in the future
        return $value === 'true';
    }

    /**
     * Parse configured policy string
     *
     * @param ?string $policy Policy specification
     *
     * @return array Policy specification as an array indexed by the policy rule type
     */
    public static function parsePolicy(?string $policy): array
    {
        $policy = explode(',', strtolower((string) $policy));
        $policy = array_map('trim', $policy);
        $policy = array_unique(array_filter($policy));

        return self::mapWithKeys($policy);
    }

    /**
     * Convert an array with password policy rules into one indexed by the rule name
     *
     * @param array $rules The rules list
     */
    private static function mapWithKeys(array $rules): array
    {
        $result = [];

        foreach ($rules as $rule) {
            $key = $rule;
            $value = null;

            if (strpos($key, ':')) {
                [$key, $value] = explode(':', $key, 2);
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
