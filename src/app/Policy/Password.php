<?php

namespace App\Policy;

use App\User;
use Illuminate\Support\Facades\Hash;

class Password
{
    /**
     * Check if the password input is the same as the existing password
     *
     * @param string $input    Password input (clear text)
     * @param string $password Existing password (hash)
     */
    public static function checkHash(string $input, string $password): bool
    {
        if (preg_match('/^\{([A-Z0-9_-]+)\}/', $password, $matches)) {
            switch ($matches[1]) {
                case 'SSHA':
                    $salt = substr(base64_decode(substr($password, 6)), 20);
                    $hash = '{SSHA}' . base64_encode(sha1($input . $salt, true) . $salt);
                    break;
                case 'SSHA512':
                    $salt = substr(base64_decode(substr($password, 9)), 64);
                    $hash = '{SSHA512}' . base64_encode(pack('H*', hash('sha512', $input . $salt)) . $salt);
                    break;
                case 'PBKDF2_SHA256':
                    // Algorithm based on https://github.com/thesubtlety/389-ds-password-check/blob/master/389ds-pwdcheck.py
                    $decoded = base64_decode(substr($password, 15));
                    $param = unpack('Niterations/a64salt', $decoded);
                    $hash = hash_pbkdf2('sha256', $input, $param['salt'], $param['iterations'], 256, true);
                    $hash = '{' . $matches[1] . '}' . base64_encode(substr($decoded, 0, 68) . $hash);
                    break;
                case 'PBKDF2-SHA512':
                    [, $algo] = explode('-', $matches[1]);
                    [$iterations, $salt] = explode('$', substr($password, 15));
                    $hash = hash_pbkdf2($algo, $input, base64_decode($salt), (int) $iterations, 0, true);
                    $hash = '{' . $matches[1] . '}' . $iterations . '$' . $salt . '$' . base64_encode($hash);
                    break;
                default:
                    \Log::warning("Unsupported password hashing algorithm {$matches[1]}");
            }

            return isset($hash) && $hash === $password;
        }

        return Hash::check($input, $password);
    }

    /**
     * Check a password against the policy rules
     *
     * @param string $password The password
     * @param ?User  $user     The user
     * @param ?User  $owner    The account owner (to get the policy rules from)
     *
     * @return array Password policy rules with validation status
     */
    public static function checkPolicy(string $password, ?User $user = null, ?User $owner = null): array
    {
        $rules = self::rules($owner);

        foreach ($rules as $name => $rule) {
            switch ($name) {
                case 'min':
                    // Check the min length
                    $status = strlen($password) >= (int) $rule['param'];
                    break;
                case 'max':
                    // Check the max length
                    $length = strlen($password);
                    $status = $length && $length <= (int) $rule['param'];
                    break;
                case 'lower':
                    // Check if password contains a lower-case character
                    $status = preg_match('/[a-z]/', $password) > 0;
                    break;
                case 'upper':
                    // Check if password contains a upper-case character
                    $status = preg_match('/[A-Z]/', $password) > 0;
                    break;
                case 'digit':
                    // Check if password contains a digit
                    $status = preg_match('/[0-9]/', $password) > 0;
                    break;
                case 'special':
                    // Check if password contains a special character
                    $status = preg_match('/[-~!@#$%^&*_+=`(){}[]|:;"\'`<>,.?\/\]/', $password) > 0;
                    break;
                case 'last':
                    // TODO: For performance reasons we might consider checking the history
                    //       only when the password passed all other checks
                    if (!$user) {
                        $status = true;
                    } else {
                        $status = self::checkPasswordHistory($user, $password, (int) $rule['param']);
                    }
                    break;
                default:
                    // Ignore unknown rule name
                    $status = true;
            }

            $rules[$name]['status'] = $status;
        }

        return $rules;
    }

    /**
     * Get the list of rules for a password
     *
     * @param ?User $owner The account owner (to get password policy from)
     * @param bool  $all   List all supported rules, instead of the enabled ones
     *
     * @return array List of rule definitions
     */
    public static function rules(?User $owner = null, bool $all = false): array
    {
        // All supported password policy rules (with default params)
        $supported = 'min:6,max:255,lower,upper,digit,special,last:3';

        // Get the password policy from the $owner settings
        if ($owner) {
            $conf = $owner->getSetting('password_policy');
        }

        // Fallback to the configured policy
        if (empty($conf)) {
            $conf = \config('app.password_policy');
        }

        $supported = Utils::parsePolicy($supported);
        $conf = Utils::parsePolicy($conf);
        $rules = $all ? $supported : $conf;

        foreach ($rules as $idx => $rule) {
            $param = $rule;

            if ($all && array_key_exists($idx, $conf)) {
                $param = $conf[$idx];
                $enabled = true;
            } else {
                $enabled = !$all;
            }

            $rules[$idx] = [
                'label' => $idx,
                'name' => \trans("app.password-rule-{$idx}", ['param' => $param]),
                'param' => $param,
                'enabled' => $enabled,
            ];
        }

        return $rules;
    }

    /**
     * Save password into user password history
     */
    public static function saveHash(User $user, string $password): void
    {
        // Note: All this is kinda heavy and complicated because we don't want to store
        // more old passwords than we need. However, except the complication/performance,
        // there's one issue with it. E.g. the policy changes from 2 to 4, and we already
        // removed the old passwords that were excessive before, but not now.

        $rules = self::rules($user->walletOwner());

        // Password history disabled?
        if (empty($rules['last']) || $rules['last']['param'] < 2) {
            return;
        }

        // Store the old password
        $user->passwords()->create(['password' => $password]);

        // Remove passwords that we don't need anymore
        $limit = $rules['last']['param'] - 1;
        $ids = $user->passwords()->latest()->limit($limit)->pluck('id')->all();

        if (count($ids) >= $limit) {
            $user->passwords()->where('id', '<', $ids[count($ids) - 1])->delete();
        }
    }

    /**
     * Check password agains <count> of old passwords in user's history
     *
     * @param User   $user     The user
     * @param string $password The password to check
     * @param int    $count    Number of old passwords to check (including current one)
     *
     * @return bool True if password is unique, False otherwise
     */
    private static function checkPasswordHistory(User $user, string $password, int $count): bool
    {
        $status = $password !== '';

        // Check if password is not the same as last X passwords
        if ($status && $count > 0) {
            // Current password
            if ($user->password) {
                $count--;
                if (Hash::check($password, $user->password)) {
                    return false;
                }
            }

            // Passwords from the history
            if ($count > 0) {
                $user->passwords()->latest()->limit($count)->get()
                    ->each(static function ($oldPassword) use (&$status, $password) {
                        if (self::checkHash($password, $oldPassword->password)) {
                            $status = false;
                            return false; // stop iteration
                        }
                    });
            }
        }

        return $status;
    }
}
