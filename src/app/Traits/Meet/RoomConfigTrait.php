<?php

namespace App\Traits\Meet;

trait RoomConfigTrait
{
    /**
     * A helper to get the room configuration.
     */
    public function getConfig(): array
    {
        $settings = $this->getSettings(['password', 'locked', 'nomedia']);

        $config = [
            'acl' => $this->getACL(),
            'locked' => $settings['locked'] === 'true',
            'nomedia' => $settings['nomedia'] === 'true',
            'password' => $settings['password'],
        ];

        return $config;
    }

    /**
     * A helper to update room configuration.
     *
     * @param array $config An array of configuration options
     *
     * @return array A list of input validation error messages
     */
    public function setConfig(array $config): array
    {
        $errors = [];

        foreach ($config as $key => $value) {
            if ($key == 'password') {
                if ($value === null || $value === '') {
                    $value = null;
                }
                // TODO: Do we have to validate the password in any way?

                $this->setSetting($key, $value);
            } elseif ($key == 'locked' || $key == 'nomedia') {
                $this->setSetting($key, $value ? 'true' : null);
            } elseif ($key == 'acl') {
                if (!empty($value) && !$this->hasSku('group-room')) {
                    $errors[$key] = \trans('validation.invalid-config-parameter');
                    continue;
                }

                $acl_errors = $this->validateACL($value);

                if (empty($acl_errors)) {
                    $this->setACL($value);
                } else {
                    $errors[$key] = $acl_errors;
                }
            } else {
                $errors[$key] = \trans('validation.invalid-config-parameter');
            }
        }

        return $errors;
    }
}
