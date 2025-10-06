<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\PassportServiceProvider;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application. This value is used when the
    | framework needs to place the application's name in a notification or
    | any other location as required by the application or its packages.
    |
    */

    'name' => env('APP_NAME', 'Kolab'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | your application so that it is used when running Artisan tasks.
    */

    'url' => env('APP_URL', 'http://localhost'),

    'passphrase' => env('APP_PASSPHRASE', null),

    'public_url' => env('APP_PUBLIC_URL', env('APP_URL', 'http://localhost')),

    'asset_url' => env('ASSET_URL'),

    'support_url' => env('SUPPORT_URL', null),

    'support_email' => env('SUPPORT_EMAIL', null),

    'webmail_url' => env('WEBMAIL_URL', null),

    'theme' => env('APP_THEME', 'default'),

    'tenant_id' => env('APP_TENANT_ID', null),

    'currency' => \strtoupper(env('APP_CURRENCY', 'CHF')),

    /*
    |--------------------------------------------------------------------------
    | Application Domain
    |--------------------------------------------------------------------------
    |
    | System domain used for user signup (kolab identity)
    */
    'domain' => env('APP_DOMAIN', 'domain.tld'),

    'website_domain' => env('APP_WEBSITE_DOMAIN', env('APP_DOMAIN', 'domain.tld')),

    // Restrict over which domains the services paths can be accessed.
    'services_allowed_domains' => explode(',', env(
        'APP_SERVICES_ALLOWED_DOMAINS',
        "webapp,kolab," . env(
            'APP_SERVICES_DOMAIN',
            "services." . env(
                'APP_WEBSITE_DOMAIN',
                env('APP_DOMAIN', 'domain.tld')
            )
        )
    )),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. We have gone
    | ahead and set this to a sensible default for you out of the box.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by the translation service provider. You are free to set this value
    | to any of the locales which will be supported by the application.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Application Fallback Locale
    |--------------------------------------------------------------------------
    |
    | The fallback locale determines the locale to use when the current one
    | is not available. You may change the value to correspond to any of
    | the language folders that are provided through your application.
    |
    */

    'fallback_locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Faker Locale
    |--------------------------------------------------------------------------
    |
    | This locale will be used by the Faker PHP library when generating fake
    | data for your database seeds. For example, this will be used to get
    | localized telephone numbers, street address information and more.
    |
    */

    'faker_locale' => 'en_US',

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is used by the Illuminate encrypter service and should be set
    | to a random, 32 character string, otherwise these encrypted strings
    | will not be safe. Please do this before deploying an application!
    |
    */

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically loaded on the
    | request to your application. Feel free to add your own services to
    | this array to grant expanded functionality to your applications.
    |
    */

    'providers' => ServiceProvider::defaultProviders()->merge([
        // Application Service Providers...
        AppServiceProvider::class,
        AuthServiceProvider::class,
        // App\Providers\BroadcastServiceProvider::class,
        EventServiceProvider::class,
        HorizonServiceProvider::class,
        PassportServiceProvider::class,
        RouteServiceProvider::class,
    ])->toArray(),

    /*
    |--------------------------------------------------------------------------
    | Class Aliases
    |--------------------------------------------------------------------------
    |
    | This array of class aliases will be registered when this application
    | is started. However, feel free to register as many as you wish as
    | the aliases are "lazy" loaded so they don't hinder performance.
    |
    */

    'aliases' => Facade::defaultAliases()->toArray(),

    'headers' => [
        'csp' => env('APP_HEADER_CSP', ""),
        'xfo' => env('APP_HEADER_XFO', ""),
    ],

    // Locations of knowledge base articles
    'kb' => [
        // An article about suspended accounts
        'account_suspended' => env('KB_ACCOUNT_SUSPENDED'),
        // An article about a way to delete an owned account
        'account_delete' => env('KB_ACCOUNT_DELETE'),
        // An article about the payment system
        'payment_system' => env('KB_PAYMENT_SYSTEM'),
    ],

    'company' => [
        'name' => env('COMPANY_NAME'),
        'address' => env('COMPANY_ADDRESS'),
        'details' => env('COMPANY_DETAILS'),
        'email' => env('COMPANY_EMAIL'),
        'logo' => env('COMPANY_LOGO'),
        'footer' => env('COMPANY_FOOTER', env('COMPANY_DETAILS')),
        'copyright' => 'Apheleia IT AG',
    ],

    'storage' => [
        'min_qty' => (int) env('STORAGE_MIN_QTY', 5), // in GB
    ],

    'vat' => [
        'mode' => (int) env('VAT_MODE', 0),
    ],

    'password_policy' => env('PASSWORD_POLICY') ?: 'min:6,max:255',

    'payment' => [
        'methods_oneoff' => env('PAYMENT_METHODS_ONEOFF', 'creditcard,paypal,banktransfer,bitcoin'),
        'methods_recurring' => env('PAYMENT_METHODS_RECURRING', 'creditcard'),
    ],

    'with_ldap' => (bool) env('APP_LDAP', true),
    'with_imap' => (bool) env('APP_IMAP', false),

    'with_admin' => (bool) env('APP_WITH_ADMIN', false),
    'with_files' => (bool) env('APP_WITH_FILES', false),
    'with_reseller' => (bool) env('APP_WITH_RESELLER', false),
    'with_services' => (bool) env('APP_WITH_SERVICES', false),
    'with_signup' => (bool) env('APP_WITH_SIGNUP', true),
    'with_subscriptions' => (bool) env('APP_WITH_SUBSCRIPTIONS', true),
    'with_wallet' => (bool) env('APP_WITH_WALLET', true),
    'with_delegation' => (bool) env('APP_WITH_DELEGATION', true),
    'with_loginas' => (bool) env('APP_WITH_LOGINAS', false),
    'with_distlists' => (bool) env('APP_WITH_DISTLISTS', true),
    'with_shared_folders' => (bool) env('APP_WITH_SHARED_FOLDERS', true),
    'with_resources' => (bool) env('APP_WITH_RESOURCES', true),
    'with_meet' => (bool) env('APP_WITH_MEET', true),
    'with_companion_app' => (bool) env('APP_WITH_COMPANION_APP', true),
    'with_user_search' => (bool) env('APP_WITH_USER_SEARCH', false),
    'with_mailfilter' => (bool) env('APP_WITH_MAILFILTER', true),

    'signup' => [
        'email_limit' => (int) env('SIGNUP_LIMIT_EMAIL', 0),
        'ip_limit' => (int) env('SIGNUP_LIMIT_IP', 0),
    ],

    'woat_ns1' => env('WOAT_NS1', 'ns01.' . env('APP_DOMAIN')),
    'woat_ns2' => env('WOAT_NS2', 'ns02.' . env('APP_DOMAIN')),

    'ratelimit_whitelist' => explode(',', env('RATELIMIT_WHITELIST', '')),
    'companion_download_link' => env(
        'COMPANION_DOWNLOAD_LINK',
        "https://mirror.apheleia-it.ch/pub/companion-app-beta.apk"
    ),

    'vpn' => [
        'token_signing_key' => env('VPN_TOKEN_SIGNING_KEY', 0),
    ],

    'mta_sts' => env('MTA_STS'),

    'shared_folder_types' => array_merge(
        ['mail'],
        \env('IMAP_WITH_GROUPWARE_DEFAULT_FOLDERS', true) ? ['event', 'contact', 'task', 'note', 'file'] : []
    ),

    // Defines a short code that is used for every verification code
    // Warning: Don't use it in production!
    'test_verification_code' => (string) env('TEST_VERIFICATION_CODE', ''),
];
