<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class UserEmailLocal implements Rule
{
    private $message;
    private $external;
    private $blacklist = [
        // These are special names in Cyrus IMAP
        'anonymous',
        'anyone',
    ];

    /**
     * Class constructor.
     *
     * @param bool $external The user in an external domain, or not
     */
    public function __construct(bool $external)
    {
        $this->external = $external;
    }

    /**
     * Determine if the validation rule passes.
     *
     * Validation of local part of an email address that's
     * going to be user's login.
     *
     * @param string $attribute Attribute name
     * @param mixed  $login     Local part of email address
     */
    public function passes($attribute, $login): bool
    {
        // Strict validation
        if (
            empty($login)
            || !is_string($login)
            || !preg_match('/^[A-Za-z0-9_.-]+$/', $login)
        ) {
            $this->message = \trans('validation.entryinvalid', ['attribute' => $attribute]);
            return false;
        }

        // Standard email address validation
        $v = Validator::make([$attribute => $login . '@test.com'], [$attribute => 'required|email']);
        if ($v->fails()) {
            $this->message = \trans('validation.entryinvalid', ['attribute' => $attribute]);
            return false;
        }

        // Global blacklist, e.g. for keywords reserved in the backend(s)
        // TODO: This should not be done when checking aliases or groups (they don't create a mailbox)
        foreach ($this->blacklist as $entry) {
            if (strcasecmp($login, $entry) == 0) {
                $this->message = \trans('validation.entryexists', ['attribute' => $attribute]);
                return false;
            }
        }

        // Check if the local part is not one of exceptions
        // (when creating an account/alias in a public domain)
        if (!$this->external) {
            $exceptions = '/^(admin|administrator|postmaster|sales|root|webmaster|info|abuse|noreply)$/i';

            if (preg_match($exceptions, $login)) {
                $this->message = \trans('validation.entryexists', ['attribute' => $attribute]);
                return false;
            }
        }

        return true;
    }

    /**
     * Get the validation error message.
     */
    public function message(): ?string
    {
        return $this->message;
    }
}
