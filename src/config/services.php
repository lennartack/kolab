<?php

use App\Backends\Helper;

return [
    /*
    ----------------------------------------------------------------------------
        Third Party Services
    ----------------------------------------------------------------------------
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'sparkpost' => [
        'secret' => env('SPARKPOST_SECRET'),
    ],

    'payment_provider' => env('PAYMENT_PROVIDER', 'mollie'),

    'mollie' => [
        'key' => env('MOLLIE_KEY'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'coinbase' => [
        'key' => env('COINBASE_KEY'),
        'webhook_secret' => env('COINBASE_WEBHOOK_SECRET'),
        'api_verify_tls' => env('COINBASE_VERIFY_TLS', true),
    ],

    'firebase' => [
        // api_key available in: Firebase Console -> Project Settings -> CLOUD MESSAGING -> Server key
        'api_key' => env('FIREBASE_API_KEY'),
        'api_url' => env('FIREBASE_API_URL', 'https://fcm.googleapis.com/fcm/send'),
        'api_verify_tls' => (bool) env('FIREBASE_API_VERIFY_TLS', true),
    ],

    'openexchangerates' => [
        'api_key' => env('OPENEXCHANGERATES_API_KEY', null),
    ],

    /*
    ----------------------------------------------------------------------------
        Kolab Services
    ----------------------------------------------------------------------------
    */

    'dav' => [
        'uri' => env('DAV_URI', 'https://proxy/'),
        'default_folders' => Helper::defaultDavFolders(),
        'verify' => (bool) env('DAV_VERIFY', true),
    ],

    'imap' => [
        'uri' => env('IMAP_URI', 'ssl://kolab:11993'),
        'admin_login' => env('IMAP_ADMIN_LOGIN', 'cyrus-admin'),
        'admin_password' => env('IMAP_ADMIN_PASSWORD', null),
        'verify_peer' => env('IMAP_VERIFY_PEER', true),
        'verify_host' => env('IMAP_VERIFY_HOST', true),
        'host' => env('IMAP_HOST', '172.18.0.5'),
        'imap_port' => env('IMAP_PORT', 12143),
        'guam_port' => env('IMAP_GUAM_PORT', 9143),
        'default_folders' => Helper::defaultImapFolders(),
    ],

    'ldap' => [
        'hosts' => explode(' ', env('LDAP_HOSTS', '127.0.0.1')),
        'port' => env('LDAP_PORT', 636),
        'use_tls' => (bool) env('LDAP_USE_TLS', false),
        'use_ssl' => (bool) env('LDAP_USE_SSL', true),

        'admin' => [
            'bind_dn' => env('LDAP_ADMIN_BIND_DN', null),
            'bind_pw' => env('LDAP_ADMIN_BIND_PW', null),
            'root_dn' => env('LDAP_ADMIN_ROOT_DN', null),
        ],

        'hosted' => [
            'root_dn' => env('LDAP_HOSTED_ROOT_DN', null),
        ],

        'domain_name_attribute' => env('LDAP_DOMAIN_NAME_ATTRIBUTE', 'associateddomain'),
        'domain_base_dn' => env('LDAP_DOMAIN_BASE_DN', null),
        'domain_filter' => env('LDAP_DOMAIN_FILTER', '(associateddomain=%s)'),
    ],

    'discovery' => [
        'activesync' => env('APP_DISCOVERY_ACTIVESYNC', 'activesync.%h'),
        'imap' => env('APP_DISCOVERY_IMAP', 'ssl://imap.%h:993'),
        'pop3' => env('APP_DISCOVERY_POP3', 'ssl://pop3.%h:995'),
        'smtp' => env('APP_DISCOVERY_SMTP', 'ssl://smtp.%h:465'),
        'name' => env('APP_NAME', 'Kolab'),
        'name_short' => env('APP_NAME_SHORT', ''),
    ],

    'activesync' => [
        'uri' => env('ACTIVESYNC_URI', 'https://proxy/Microsoft-Server-ActiveSync'),
    ],

    'freebusy' => [
        'uri' => env('FREEBUSY_URI', 'https://proxy/'),
    ],

    'smtp' => [
        'host' => env('SMTP_HOST', '172.18.0.5'),
        'port' => env('SMTP_PORT', 10465),
    ],

    'wopi' => [
        'uri' => env('WOPI_URI', 'http://roundcube/chwala/'),
    ],

    'webmail' => [
        'uri' => env('WEBMAIL_URI', 'http://roundcube/roundcubemail/'),
    ],
];
