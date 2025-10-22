<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UserEmailDomain implements Rule
{
    private $message;
    private $domains;

    /**
     * Class constructor.
     *
     * @param array|null $domains Allowed domains
     */
    public function __construct($domains = null)
    {
        $this->domains = $domains;
    }

    /**
     * Determine if the validation rule passes.
     *
     * Validation of a domain part of an email address that's
     * going to be user's login.
     *
     * @param string $attribute Attribute name
     * @param mixed  $domain    Domain part of email address
     */
    public function passes($attribute, $domain): bool
    {
        // don't allow @localhost and other non-fqdn
        if (
            empty($domain)
            || !is_string($domain)
            || !str_contains($domain, '.')
            || stripos($domain, 'www.') === 0
        ) {
            $this->message = \trans('validation.domaininvalid');
            return false;
        }

        // Check the max length, according to the database column length
        if (strlen($domain) > 191) {
            $this->message = \trans('validation.domaininvalid');
            return false;
        }

        $domain = Str::lower($domain);

        // Use email validator to validate the domain part
        // Note: The 'filter' validator does not allow IDN domains
        $v = Validator::make(['email' => 'user@' . $domain], ['email' => 'required|email:filter']);
        if ($v->fails()) {
            $this->message = \trans('validation.domaininvalid');
            return false;
        }

        // Check if specified domain is allowed for signup
        if (is_array($this->domains) && !in_array($domain, $this->domains)) {
            $this->message = \trans('validation.domaininvalid');
            return false;
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
