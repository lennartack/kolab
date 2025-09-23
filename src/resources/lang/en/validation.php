<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'The :attribute must be accepted.',
    'accepted_if' => 'The :attribute must be accepted when :other is :value.',
    'active_url' => 'The :attribute is not a valid URL.',
    'after' => 'The :attribute must be a date after :date.',
    'after_or_equal' => 'The :attribute must be a date after or equal to :date.',
    'alpha' => 'The :attribute must only contain letters.',
    'alpha_dash' => 'The :attribute must only contain letters, numbers, dashes and underscores.',
    'alpha_num' => 'The :attribute must only contain letters and numbers.',
    'array' => 'The :attribute must be an array.',
    'before' => 'The :attribute must be a date before :date.',
    'before_or_equal' => 'The :attribute must be a date before or equal to :date.',
    'between' => [
        'numeric' => 'The :attribute must be between :min and :max.',
        'file' => 'The :attribute must be between :min and :max kilobytes.',
        'string' => 'The :attribute must be between :min and :max characters.',
        'array' => 'The :attribute must have between :min and :max items.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute is not a valid date.',
    'date_equals' => 'The :attribute must be a date equal to :date.',
    'date_format' => 'The :attribute does not match the format :format.',
    'declined' => 'The :attribute must be declined.',
    'declined_if' => 'The :attribute must be declined when :other is :value.',
    'different' => 'The :attribute and :other must be different.',
    'digits' => 'The :attribute must be :digits digits.',
    'digits_between' => 'The :attribute must be between :min and :max digits.',
    'dimensions' => 'The :attribute has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'email' => 'The :attribute must be a valid email address.',
    'ends_with' => 'The :attribute must end with one of the following: :values',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'file' => 'The :attribute must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'numeric' => 'The :attribute must be greater than :value.',
        'file' => 'The :attribute must be greater than :value kilobytes.',
        'string' => 'The :attribute must be greater than :value characters.',
        'array' => 'The :attribute must have more than :value items.',
    ],
    'gte' => [
        'numeric' => 'The :attribute must be greater than or equal :value.',
        'file' => 'The :attribute must be greater than or equal :value kilobytes.',
        'string' => 'The :attribute must be greater than or equal :value characters.',
        'array' => 'The :attribute must have :value items or more.',
    ],
    'image' => 'The :attribute must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => 'The :attribute field does not exist in :other.',
    'integer' => 'The :attribute must be an integer.',
    'ip' => 'The :attribute must be a valid IP address.',
    'ipv4' => 'The :attribute must be a valid IPv4 address.',
    'ipv6' => 'The :attribute must be a valid IPv6 address.',
    'json' => 'The :attribute must be a valid JSON string.',
    'lt' => [
        'numeric' => 'The :attribute must be less than :value.',
        'file' => 'The :attribute must be less than :value kilobytes.',
        'string' => 'The :attribute must be less than :value characters.',
        'array' => 'The :attribute must have less than :value items.',
    ],
    'lte' => [
        'numeric' => 'The :attribute must be less than or equal :value.',
        'file' => 'The :attribute must be less than or equal :value kilobytes.',
        'string' => 'The :attribute must be less than or equal :value characters.',
        'array' => 'The :attribute must not have more than :value items.',
    ],
    'max' => [
        'numeric' => 'The :attribute may not be greater than :max.',
        'file' => 'The :attribute may not be greater than :max kilobytes.',
        'string' => 'The :attribute may not be greater than :max characters.',
        'array' => 'The :attribute may not have more than :max items.',
    ],
    'mac_address' => 'The :attribute must be a valid MAC address.',
    'mimes' => 'The :attribute must be a file of type: :values.',
    'mimetypes' => 'The :attribute must be a file of type: :values.',
    'min' => [
        'numeric' => 'The :attribute must be at least :min.',
        'file' => 'The :attribute must be at least :min kilobytes.',
        'string' => 'The :attribute must be at least :min characters.',
        'array' => 'The :attribute must have at least :min items.',
    ],
    'multiple_of' => 'The :attribute must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute format is invalid.',
    'numeric' => 'The :attribute must be a number.',
    'present' => 'The :attribute field must be present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute format is invalid.',
    'regex_format' => 'The :attribute does not match the format :format.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute and :other must match.',
    'size' => [
        'numeric' => 'The :attribute must be :size.',
        'file' => 'The :attribute must be :size kilobytes.',
        'string' => 'The :attribute must be :size characters.',
        'array' => 'The :attribute must contain :size items.',
    ],
    'starts_with' => 'The :attribute must start with one of the following: :values',
    'string' => 'The :attribute must be a string.',
    'timezone' => 'The :attribute must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'url' => 'The :attribute must be a valid URL.',
    'uuid' => 'The :attribute must be a valid UUID.',

    'invalidvalue' => 'Invalid value',
    'invalidvalueof' => 'Invalid value of request property: :attribute.',
    '2fareq' => 'Second factor code is required.',
    '2fainvalid' => 'Second factor code is invalid.',
    'emailinvalid' => 'The specified email address is invalid.',
    'domaininvalid' => 'The specified domain is invalid.',
    'domainnotavailable' => 'The specified domain is not available.',
    'delegateeinvalid' => 'The specified email address is not a valid delegation target.',
    'delegationoptioninvalid' => 'The specified delegation options are invalid.',
    'logininvalid' => 'The specified login is invalid.',
    'loginexists' => 'The specified login is not available.',
    'domainexists' => 'The specified domain is not available.',
    'noemailorphone' => 'The specified text is neither a valid email address nor a phone number.',
    'packageinvalid' => 'Invalid package selected.',
    'packagerequired' => 'Package is required.',
    'usernotexists' => 'Unable to find user.',
    'voucherinvalid' => 'The voucher code is invalid or expired.',
    'noextemail' => 'This user has no external email address.',
    'entryinvalid' => 'The specified :attribute is invalid.',
    'entryexists' => 'The specified :attribute is not available.',
    'minamount' => 'Minimum amount for a single payment is :amount.',
    'minamountdebt' => 'The specified amount does not cover the balance on the account.',
    'notalocaluser' => 'The specified email address does not exist.',
    'memberislist' => 'A recipient cannot be the same as the list address.',
    'listmembersrequired' => 'At least one recipient is required.',
    'spf-entry-invalid' => 'The entry format is invalid. Expected a domain name starting with a dot.',
    'sp-entry-invalid' => 'The entry format is invalid. Expected an email, domain, or part of it.',
    'acl-entry-invalid' => 'The entry format is invalid. Expected an email address.',
    'acl-permission-invalid' => 'The specified permission is invalid.',
    'file-perm-exists' => 'File permission already exists.',
    'file-perm-invalid' => 'The file permission is invalid.',
    'file-name-exists' => 'The file name already exists.',
    'file-name-invalid' => 'The file name is invalid.',
    'file-name-toolong' => 'The file name is too long.',
    'fsparentunknown' => 'Specified parent does not exist.',
    'geolockinerror' => 'The request location is not allowed.',
    'ipolicy-invalid' => 'The specified invitation policy is invalid.',
    'invalid-config-parameter' => 'The requested configuration parameter is not supported.',
    'nameexists' => 'The specified name is not available.',
    'nameinvalid' => 'The specified name is invalid.',
    'password-policy-error' => 'Specified password does not comply with the policy.',
    'invalid-externalsender-domains' => 'Specified configuration is invalid. Expected a list domain names.',
    'invalid-limit-geo' => 'Specified configuration is invalid. Expected a list of two-letter country codes.',
    'invalid-limit-geo-missing-current' => 'Specified configuration is invalid. Missing country of the current connection (:code).',
    'invalid-password-policy' => 'Specified password policy is invalid.',
    'password-policy-min-len-error' => 'Minimum password length cannot be less than :min.',
    'password-policy-max-len-error' => 'Maximum password length cannot be more than :max.',
    'password-policy-last-error' => 'The minimum value for last N passwords is :last.',
    'referralcodeinvalid' => 'The referral program code is invalid.',
    'signuptokeninvalid' => 'The signup token is invalid.',
    'verificationcodeinvalid' => 'The verification code is invalid or expired.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],
];
