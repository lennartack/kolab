<?php

namespace App\Traits;

use App\Policy\Password;
use App\Policy\Utils as PolicyUtils;
use App\Utils;
use Illuminate\Support\Facades\Validator;

trait UserConfigTrait
{
    /**
     * A helper to get the user configuration.
     *
     * @param bool $include_account_policies Get the policies options from the account owner
     */
    public function getConfig($include_account_policies = false): array
    {
        $settings = $this->getSettings([
            'externalsender_config',
            'externalsender_policy',
            'externalsender_policy_domains',
            'greylist_enabled',
            'greylist_policy',
            'itip_config',
            'itip_policy',
            'limit_geo',
            'max_password_age',
            'password_policy',
        ]);

        $config = [];
        foreach ($settings as $key => $value) {
            switch ($key) {
                case 'externalsender_config':
                case 'greylist_enabled':
                case 'itip_config':
                    $config[$key] = self::boolOrNull($value);
                    break;
                case 'externalsender_policy_domains':
                case 'limit_geo':
                    $config[$key] = $value ? json_decode($value, true) : [];
                    break;
                case 'greylist_policy':
                    $config[$key] = $value !== 'false';
                    break;
                case 'externalsender_policy':
                case 'itip_policy':
                    $config[$key] = $value === 'true';
                    break;
                case 'max_password_age':
                case 'password_policy':
                default:
                    $config[$key] = $value;
            }
        }

        // If user is not an account owner read the policy from the owner config
        if ($include_account_policies) {
            if ($this->account && $this->account->id != $this->id) {
                foreach ($this->account->getConfig() as $name => $value) {
                    if (str_contains($name, '_policy')) {
                        $config[$name] = $value;
                    }
                }
            }
        }

        return $config;
    }

    /**
     * A helper to update user configuration.
     *
     * @param array $config An array of configuration options
     *
     * @return array A list of input validation error messages
     */
    public function setConfig(array $config): array
    {
        $errors = [];

        foreach ($config as $key => $value) {
            if (in_array($key, ['greylist_policy', 'itip_policy', 'externalsender_policy'])) {
                $this->setSetting($key, $value ? 'true' : 'false');
            } elseif (in_array($key, ['greylist_enabled', 'itip_config', 'externalsender_config'])) {
                $this->setSetting($key, $value === null ? null : ($value ? 'true' : 'false'));
            } elseif ($key == 'limit_geo') {
                if ($error = $this->validateLimitGeo($value)) {
                    $errors[$key] = $error;
                    continue;
                }

                $this->setSetting($key, !empty($value) ? json_encode($value) : null);
            } elseif ($key == 'externalsender_policy_domains') {
                if ($error = $this->validateExternalsenderPolicyDomains($value)) {
                    $errors[$key] = $error;
                    continue;
                }

                $this->setSetting($key, !empty($value) ? json_encode($value) : null);
            } elseif ($key == 'max_password_age') {
                $this->setSetting($key, (int) $value > 0 ? ((string) (int) $value) : null);
            } elseif ($key == 'password_policy') {
                // Validate the syntax and make sure min and max is included
                if (
                    !is_string($value)
                    || !str_contains($value, 'min:')
                    || !str_contains($value, 'max:')
                    || !preg_match('/^[a-z0-9:,]+$/', $value)
                ) {
                    $errors[$key] = \trans('validation.invalid-password-policy');
                    continue;
                }

                foreach (explode(',', $value) as $rule) {
                    if ($error = $this->validatePasswordPolicyRule($rule)) {
                        $errors[$key] = $error;
                        continue 2;
                    }
                }

                $this->setSetting($key, $value);
            } else {
                $errors[$key] = \trans('validation.invalid-config-parameter');
            }
        }

        return $errors;
    }

    /**
     * Convert string value into real value (bool or null)
     */
    private static function boolOrNull($value): ?bool
    {
        return $value === 'true' ? true : ($value === 'false' ? false : null);
    }

    /**
     * Validates externalsender_policy_domains setting
     *
     * @param mixed $value List of domains input
     *
     * @return ?string An error message on error, Null otherwise
     */
    protected function validateExternalsenderPolicyDomains(&$value): ?string
    {
        if (!is_array($value)) {
            return \trans('validation.invalid-externalsender-domains');
        }

        foreach ($value as $idx => $domain) {
            $domain = \strtolower($domain);

            // TODO: Support asterisk expressions, e.g. *.domain.tld
            $v = Validator::make(['email' => 'user@' . $domain], ['email' => 'required|email:strict']);
            if ($v->fails()) {
                return \trans('validation.invalid-externalsender-domains');
            }

            $value[$idx] = $domain;
        }

        return null;
    }

    /**
     * Validates password policy rule.
     *
     * @param string $rule Policy rule
     *
     * @return ?string An error message on error, Null otherwise
     */
    protected function validatePasswordPolicyRule(string $rule): ?string
    {
        $regexp = [
            'min:[0-9]+', 'max:[0-9]+', 'upper', 'lower', 'digit', 'special', 'last:[0-9]+',
        ];

        if (empty($rule) || !preg_match('/^(' . implode('|', $regexp) . ')$/', $rule)) {
            return \trans('validation.invalid-password-policy');
        }

        $systemPolicy = PolicyUtils::parsePolicy(\config('app.password_policy'));

        // Min/Max values cannot exceed the system defaults, i.e. if system policy
        // is min:5, user's policy cannot be set to a smaller number.
        if (!empty($systemPolicy['min']) && str_starts_with($rule, 'min:')) {
            $value = trim(substr($rule, 4));
            if ($value < $systemPolicy['min']) {
                return \trans('validation.password-policy-min-len-error', ['min' => $systemPolicy['min']]);
            }
        }

        if (!empty($systemPolicy['max']) && str_starts_with($rule, 'max:')) {
            $value = trim(substr($rule, 4));
            if ($value > $systemPolicy['max']) {
                return \trans('validation.password-policy-max-len-error', ['max' => $systemPolicy['max']]);
            }
        }

        if (!empty($systemPolicy['last']) && str_starts_with($rule, 'last:')) {
            $value = trim(substr($rule, 5));
            if ($value < $systemPolicy['last']) {
                return \trans('validation.password-policy-last-error', ['last' => $systemPolicy['last']]);
            }
        }

        return null;
    }

    /**
     * Validates limit_geo value
     *
     * @param mixed $value Geo-lock input
     *
     * @return ?string An error message on error, Null otherwise
     */
    protected function validateLimitGeo(&$value): ?string
    {
        if (!is_array($value)) {
            return \trans('validation.invalid-limit-geo');
        }

        foreach ($value as $idx => $country) {
            if (!preg_match('/^[a-zA-Z]{2}$/', $country)) {
                return \trans('validation.invalid-limit-geo');
            }

            $value[$idx] = \strtoupper($country);
        }

        if (count($value) > 250) {
            return \trans('validation.invalid-limit-geo');
        }
        if (count($value)) {
            // There MUST be country of the current connection included
            $currentCountry = Utils::countryForRequest();

            if (!in_array($currentCountry, $value)) {
                return \trans('validation.invalid-limit-geo-missing-current', ['code' => $currentCountry]);
            }
        }

        return null;
    }
}
