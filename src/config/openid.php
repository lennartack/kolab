<?php

use App\Auth\IdentityRepository;
use Lcobucci\JWT\Signer\Rsa\Sha256;

return [
    'passport' => [
        // Place your Passport and OpenID Connect scopes here.
        // To receive an `id_token`, you should at least provide the openid scope.
        'tokens_can' => [
            'openid' => 'Enable OpenID Connect',
            'email' => 'Information about your email address',
            // 'profile' => 'Information about your profile',
            // 'phone' => 'Information about your phone numbers',
            // 'address' => 'Information about your address',
            // 'login' => 'See your login information',
            'auth.token' => 'Kolab authentication token',
        ],
    ],

    // Place your custom claim sets here.
    'custom_claim_sets' => [
        // 'login' => [
        //     'last-login',
        // ],
        // 'company' => [
        //     'company_name',
        //     'company_address',
        //     'company_phone',
        //     'company_email',
        // ],
        'auth.token' => [
            'auth.token',
        ],
    ],

    // You can override the repositories below.
    'repositories' => [
        // 'identity' => \OpenIDConnect\Repositories\IdentityRepository::class,
        'identity' => IdentityRepository::class,
    ],

    'routes' => [
        // When set to true, this package will expose the OpenID Connect Discovery endpoint.
        // - /.well-known/openid-configuration
        'discovery' => true,

        // When set to true, this package will expose the JSON Web Key Set endpoint.
        'jwks' => true,

        // Optional URL to change the JWKS path to align with your custom Passport routes.
        // Defaults to /oauth/jwks
        'jwks_url' => '/oauth/jwks',
    ],

    // Settings for the discovery endpoint
    'discovery' => [
        // Hide scopes that aren't from the OpenID Core spec from the Discovery,
        // default = false (all scopes are listed)
        'hide_scopes' => false,
    ],

    // The signer to be used
    'signer' => Sha256::class,

    // Optional associative array that will be used to set headers on the JWT
    'token_headers' => [
        // https://datatracker.ietf.org/doc/html/rfc7517#section-4.5
        // This should be optional according to rfc, but is required by the nextcloud user_oidc plugin.
        'kid' => 'key1',
    ],

    // By default, microseconds are included.
    'use_microseconds' => true,
];
