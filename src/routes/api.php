<?php

use App\Http\Controllers\API;
use Illuminate\Support\Facades\Route;

Route::get('health/readiness', [API\V4\HealthController::class, 'readiness']);
Route::get('health/liveness', [API\V4\HealthController::class, 'liveness']);

Route::post('oauth/approve', [API\AuthController::class, 'oauthApprove'])
    ->middleware(['auth:api']);

Route::group(
    [
        'middleware' => 'api',
        'prefix' => 'auth',
    ],
    static function () {
        Route::post('login', [API\AuthController::class, 'login']);

        Route::post('password-policy-check', [API\V4\PolicyController::class, 'checkPassword']);
        Route::post('password-reset/init', [API\PasswordResetController::class, 'init']);
        Route::post('password-reset/verify', [API\PasswordResetController::class, 'verify']);
        Route::post('password-reset', [API\PasswordResetController::class, 'reset']);
        Route::post('password-reset-expired', [API\PasswordResetController::class, 'resetExpired']);

        if (\config('app.with_signup')) {
            Route::get('signup/domains', [API\SignupController::class, 'domains']);
            Route::post('signup/init', [API\SignupController::class, 'init']);
            Route::get('signup/invitations/{id}', [API\SignupController::class, 'invitation']);
            Route::get('signup/plans', [API\SignupController::class, 'plans']);
            Route::post('signup/validate', [API\SignupController::class, 'signupValidate']);
            Route::post('signup/verify', [API\SignupController::class, 'verify']);
            Route::post('signup', [API\SignupController::class, 'signup']);
        }

        Route::group(
            ['middleware' => ['auth:api', 'scope:api']],
            static function () {
                Route::get('info', [API\AuthController::class, 'info']);
                Route::get('location', [API\AuthController::class, 'location']);
                Route::post('logout', [API\AuthController::class, 'logout']);
                Route::post('refresh', [API\AuthController::class, 'refresh']);
            }
        );
    }
);

Route::group(
    [
        'middleware' => ['regularHosts', 'auth:api', 'scope:mfa,api'],
        'prefix' => 'v4',
    ],
    static function () {
        Route::post('auth-attempts/{id}/confirm', [API\V4\AuthAttemptsController::class, 'confirm']);
        Route::post('auth-attempts/{id}/deny', [API\V4\AuthAttemptsController::class, 'deny']);
        Route::get('auth-attempts/{id}/details', [API\V4\AuthAttemptsController::class, 'details']);
        Route::get('auth-attempts', [API\V4\AuthAttemptsController::class, 'index']);

        Route::post('companion/register', [API\V4\CompanionAppsController::class, 'register']);
    }
);

if (\config('app.with_files')) {
    Route::group(
        [
            'middleware' => ($middleware = ['auth:api', 'scope:fs,api']),
            'prefix' => 'v4',
        ],
        static function () use ($middleware) {
            Route::apiResource('fs', API\V4\FsController::class);
            Route::get('fs/{itemId}/permissions', [API\V4\FsController::class, 'getPermissions']);
            Route::post('fs/{itemId}/permissions', [API\V4\FsController::class, 'createPermission']);
            Route::put('fs/{itemId}/permissions/{id}', [API\V4\FsController::class, 'updatePermission']);
            Route::delete('fs/{itemId}/permissions/{id}', [API\V4\FsController::class, 'deletePermission']);

            Route::post('fs/uploads/{id}', [API\V4\FsController::class, 'upload'])
                ->withoutMiddleware($middleware)->middleware(['api']);
            Route::get('fs/downloads/{id}', [API\V4\FsController::class, 'download'])
                ->withoutMiddleware($middleware);
        }
    );
}

if (\config('app.with_admin')) {
    Route::group(
        [
            'domain' => 'admin.' . \config('app.website_domain'),
            'middleware' => ['auth:api', 'admin'],
            'prefix' => 'v4',
        ],
        static function () {
            Route::apiResource('domains', API\V4\Admin\DomainsController::class);
            Route::get('domains/{id}/skus', [API\V4\Admin\DomainsController::class, 'skus']);
            Route::post('domains/{id}/suspend', [API\V4\Admin\DomainsController::class, 'suspend']);
            Route::post('domains/{id}/unsuspend', [API\V4\Admin\DomainsController::class, 'unsuspend']);

            Route::get('eventlog/{type}/{id}', [API\V4\Admin\EventLogController::class, 'index']);

            Route::apiResource('groups', API\V4\Admin\GroupsController::class);
            Route::post('groups/{id}/suspend', [API\V4\Admin\GroupsController::class, 'suspend']);
            Route::post('groups/{id}/unsuspend', [API\V4\Admin\GroupsController::class, 'unsuspend']);

            Route::apiResource('resources', API\V4\Admin\ResourcesController::class);
            Route::apiResource('shared-folders', API\V4\Admin\SharedFoldersController::class);
            Route::apiResource('skus', API\V4\Admin\SkusController::class);

            Route::apiResource('users', API\V4\Admin\UsersController::class);
            Route::get('users/{id}/discounts', [API\V4\Admin\DiscountsController::class, 'userDiscounts']);
            Route::post('users/{id}/reset-2fa', [API\V4\Admin\UsersController::class, 'reset2FA']);
            Route::post('users/{id}/reset-geolock', [API\V4\Admin\UsersController::class, 'resetGeoLock']);
            Route::post('users/{id}/resync', [API\V4\Admin\UsersController::class, 'resync']);
            Route::get('users/{id}/skus', [API\V4\Admin\UsersController::class, 'skus']);
            Route::post('users/{id}/skus/{sku}', [API\V4\Admin\UsersController::class, 'setSku']);
            Route::post('users/{id}/suspend', [API\V4\Admin\UsersController::class, 'suspend']);
            Route::post('users/{id}/unsuspend', [API\V4\Admin\UsersController::class, 'unsuspend']);

            Route::apiResource('wallets', API\V4\Admin\WalletsController::class);
            Route::post('wallets/{id}/one-off', [API\V4\Admin\WalletsController::class, 'oneOff']);
            Route::get('wallets/{id}/receipts', [API\V4\Admin\WalletsController::class, 'receipts']);
            Route::get('wallets/{id}/receipts/{receipt}', [API\V4\Admin\WalletsController::class, 'receiptDownload']);
            Route::get('wallets/{id}/transactions', [API\V4\Admin\WalletsController::class, 'transactions']);

            Route::get('stats/chart/{chart}', [API\V4\Admin\StatsController::class, 'chart']);

            Route::get('inspect-request', [API\V4\Admin\UsersController::class, 'inspectRequest'])
                ->withoutMiddleware(['auth:api', 'admin']);
        }
    );
}

if (\config('app.with_reseller')) {
    Route::group(
        [
            'domain' => 'reseller.' . \config('app.website_domain'),
            'middleware' => ['auth:api', 'reseller'],
            'prefix' => 'v4',
        ],
        static function () {
            Route::apiResource('domains', API\V4\Reseller\DomainsController::class);
            Route::get('domains/{id}/skus', [API\V4\Reseller\DomainsController::class, 'skus']);
            Route::post('domains/{id}/suspend', [API\V4\Reseller\DomainsController::class, 'suspend']);
            Route::post('domains/{id}/unsuspend', [API\V4\Reseller\DomainsController::class, 'unsuspend']);

            Route::get('eventlog/{type}/{id}', [API\V4\Reseller\EventLogController::class, 'index']);

            Route::apiResource('groups', API\V4\Reseller\GroupsController::class);
            Route::post('groups/{id}/suspend', [API\V4\Reseller\GroupsController::class, 'suspend']);
            Route::post('groups/{id}/unsuspend', [API\V4\Reseller\GroupsController::class, 'unsuspend']);

            Route::apiResource('invitations', API\V4\Reseller\InvitationsController::class);
            Route::post('invitations/{id}/resend', [API\V4\Reseller\InvitationsController::class, 'resend']);

            Route::post('payments', [API\V4\Reseller\PaymentsController::class, 'store']);
            Route::get('payments/mandate', [API\V4\Reseller\PaymentsController::class, 'mandate']);
            Route::post('payments/mandate', [API\V4\Reseller\PaymentsController::class, 'mandateCreate']);
            Route::put('payments/mandate', [API\V4\Reseller\PaymentsController::class, 'mandateUpdate']);
            Route::delete('payments/mandate', [API\V4\Reseller\PaymentsController::class, 'mandateDelete']);
            Route::get('payments/methods', [API\V4\Reseller\PaymentsController::class, 'paymentMethods']);
            Route::get('payments/pending', [API\V4\Reseller\PaymentsController::class, 'payments']);
            Route::get('payments/has-pending', [API\V4\Reseller\PaymentsController::class, 'hasPayments']);

            Route::apiResource('resources', API\V4\Reseller\ResourcesController::class);
            Route::apiResource('shared-folders', API\V4\Reseller\SharedFoldersController::class);
            Route::apiResource('skus', API\V4\Reseller\SkusController::class);

            Route::apiResource('users', API\V4\Reseller\UsersController::class);
            Route::get('users/{id}/discounts', [API\V4\Reseller\DiscountsController::class, 'userDiscounts']);
            Route::post('users/{id}/reset-2fa', [API\V4\Reseller\UsersController::class, 'reset2FA']);
            Route::post('users/{id}/reset-geolock', [API\V4\Reseller\UsersController::class, 'resetGeoLock']);
            Route::post('users/{id}/resync', [API\V4\Reseller\UsersController::class, 'resync']);
            Route::get('users/{id}/skus', [API\V4\Reseller\UsersController::class, 'skus']);
            Route::post('users/{id}/skus/{sku}', [API\V4\Reseller\UsersController::class, 'setSku']);
            Route::post('users/{id}/suspend', [API\V4\Reseller\UsersController::class, 'suspend']);
            Route::post('users/{id}/unsuspend', [API\V4\Reseller\UsersController::class, 'unsuspend']);

            Route::apiResource('wallets', API\V4\Reseller\WalletsController::class);
            Route::post('wallets/{id}/one-off', [API\V4\Reseller\WalletsController::class, 'oneOff']);
            Route::get('wallets/{id}/receipts', [API\V4\Reseller\WalletsController::class, 'receipts']);
            Route::get('wallets/{id}/receipts/{receipt}', [API\V4\Reseller\WalletsController::class, 'receiptDownload']);
            Route::get('wallets/{id}/transactions', [API\V4\Reseller\WalletsController::class, 'transactions']);

            Route::get('stats/chart/{chart}', [API\V4\Reseller\StatsController::class, 'chart']);
        }
    );
}

Route::group(
    [
        'middleware' => ['regularHosts', 'auth:api', 'scope:api'],
        'prefix' => 'v4',
    ],
    static function () {
        Route::apiResource('companions', API\V4\CompanionAppsController::class);
        // This must not be accessible with the 2fa token,
        // to prevent an attacker from pairing a new device with a stolen token.
        Route::get('companions/{id}/pairing', [API\V4\CompanionAppsController::class, 'pairing']);

        Route::get('config/webmail', [API\V4\ConfigController::class, 'webmail']);

        Route::post('device/{token}/claim', [API\V4\DeviceController::class, 'claim']);
        Route::get('device/{token}', [API\V4\DeviceController::class, 'info'])
            ->withoutMiddleware(['auth:api', 'scope:api']);
        Route::get('device/{token}/plans', [API\V4\DeviceController::class, 'plans'])
            ->withoutMiddleware(['auth:api', 'scope:api']);
        Route::post('device/{token}/signup', [API\V4\DeviceController::class, 'signup'])
            ->withoutMiddleware(['auth:api', 'scope:api']);

        Route::apiResource('domains', API\V4\DomainsController::class);
        Route::get('domains/{id}/confirm', [API\V4\DomainsController::class, 'confirm']);
        Route::get('domains/{id}/skus', [API\V4\DomainsController::class, 'skus']);
        Route::get('domains/{id}/status', [API\V4\DomainsController::class, 'status']);
        Route::post('domains/{id}/config', [API\V4\DomainsController::class, 'setConfig']);

        Route::apiResource('groups', API\V4\GroupsController::class);
        Route::get('groups/{id}/skus', [API\V4\GroupsController::class, 'skus']);
        Route::get('groups/{id}/status', [API\V4\GroupsController::class, 'status']);
        Route::post('groups/{id}/config', [API\V4\GroupsController::class, 'setConfig']);

        Route::apiResource('packages', API\V4\PackagesController::class);

        Route::apiResource('rooms', API\V4\RoomsController::class);
        Route::post('rooms/{id}/config', [API\V4\RoomsController::class, 'setConfig']);
        Route::get('rooms/{id}/skus', [API\V4\RoomsController::class, 'skus']);

        Route::post('meet/rooms/{id}', [API\V4\MeetController::class, 'joinRoom'])
            ->withoutMiddleware(['auth:api', 'scope:api']);

        Route::apiResource('resources', API\V4\ResourcesController::class);
        Route::get('resources/{id}/skus', [API\V4\ResourcesController::class, 'skus']);
        Route::get('resources/{id}/status', [API\V4\ResourcesController::class, 'status']);
        Route::post('resources/{id}/config', [API\V4\ResourcesController::class, 'setConfig']);

        Route::apiResource('shared-folders', API\V4\SharedFoldersController::class);
        Route::get('shared-folders/{id}/skus', [API\V4\SharedFoldersController::class, 'skus']);
        Route::get('shared-folders/{id}/status', [API\V4\SharedFoldersController::class, 'status']);
        Route::post('shared-folders/{id}/config', [API\V4\SharedFoldersController::class, 'setConfig']);

        Route::apiResource('skus', API\V4\SkusController::class);

        Route::apiResource('users', API\V4\UsersController::class);
        Route::post('users/{id}/config', [API\V4\UsersController::class, 'setConfig']);
        Route::post('users/{id}/login-as', [API\V4\UsersController::class, 'loginAs']);
        Route::get('users/{id}/skus', [API\V4\UsersController::class, 'skus']);
        Route::get('users/{id}/status', [API\V4\UsersController::class, 'status']);
        Route::post('users/{id}/code/{code}', [API\V4\UsersController::class, 'codeValidation']);

        if (\config('app.with_delegation')) {
            Route::get('users/{id}/delegations', [API\V4\UsersController::class, 'delegations']);
            Route::post('users/{id}/delegations', [API\V4\UsersController::class, 'createDelegation']);
            Route::delete('users/{id}/delegations/{email}', [API\V4\UsersController::class, 'deleteDelegation']);
            Route::get('users/{id}/delegators', [API\V4\UsersController::class, 'delegators']);
        }

        Route::apiResource('wallets', API\V4\WalletsController::class);
        Route::get('wallets/{id}/transactions', [API\V4\WalletsController::class, 'transactions']);
        Route::get('wallets/{id}/receipts', [API\V4\WalletsController::class, 'receipts']);
        Route::get('wallets/{id}/receipts/{receipt}', [API\V4\WalletsController::class, 'receiptDownload']);
        Route::get('wallets/{id}/referral-programs', [API\V4\WalletsController::class, 'referralPrograms']);
        Route::post('wallets/{id}/controllers/{userid}', [API\V4\WalletsController::class, 'controllerAdd']);
        Route::delete('wallets/{id}/controllers/{userid}', [API\V4\WalletsController::class, 'controllerDelete']);

        Route::get('policies', [API\V4\PolicyController::class, 'index']);
        Route::post('password-reset/code', [API\PasswordResetController::class, 'codeCreate']);
        Route::delete('password-reset/code/{id}', [API\PasswordResetController::class, 'codeDelete']);

        Route::post('payments', [API\V4\PaymentsController::class, 'store']);
        // Route::delete('payments', [API\V4\PaymentsController::class, 'cancel']);
        Route::get('payments/mandate', [API\V4\PaymentsController::class, 'mandate']);
        Route::post('payments/mandate', [API\V4\PaymentsController::class, 'mandateCreate']);
        Route::put('payments/mandate', [API\V4\PaymentsController::class, 'mandateUpdate']);
        Route::delete('payments/mandate', [API\V4\PaymentsController::class, 'mandateDelete']);
        Route::post('payments/mandate/reset', [API\V4\PaymentsController::class, 'mandateReset']);
        Route::get('payments/methods', [API\V4\PaymentsController::class, 'paymentMethods']);
        Route::get('payments/pending', [API\V4\PaymentsController::class, 'payments']);
        Route::get('payments/has-pending', [API\V4\PaymentsController::class, 'hasPayments']);
        Route::get('payments/status', [API\V4\PaymentsController::class, 'paymentStatus']);

        Route::get('search/self', [API\V4\SearchController::class, 'searchSelf']);
        Route::get('search/contacts', [API\V4\SearchController::class, 'searchContacts']);
        Route::get('search/user', [API\V4\SearchController::class, 'searchUser']);

        Route::post('support/request', [API\V4\SupportController::class, 'request'])
            ->withoutMiddleware(['auth:api', 'scope:api'])
            ->middleware(['api']);

        Route::get('vpn/token', [API\V4\VPNController::class, 'token']);
        Route::get('license/{type}', [API\V4\LicenseController::class, 'license']);
    }
);

Route::group(
    [
        'domain' => \config('app.website_domain'),
        'prefix' => 'webhooks',
    ],
    static function () {
        Route::post('payment/{provider}', [API\V4\PaymentsController::class, 'webhook']);
        Route::post('meet', [API\V4\MeetController::class, 'webhook']);
    }
);

if (\config('app.with_services')) {
    Route::group(
        [
            'prefix' => 'webhooks',
        ],
        static function () {
            Route::get('metrics/swoole', [API\V4\MetricsController::class, 'swooleMetrics']);
        }
    );
    Route::group(
        [
            'middleware' => ['allowedHosts'],
            'prefix' => 'webhooks',
        ],
        static function () {
            Route::get('nginx', [API\V4\NGINXController::class, 'authenticate']);
            Route::get('nginx-roundcube', [API\V4\NGINXController::class, 'authenticateRoundcube']);
            Route::get('nginx-httpauth', [API\V4\NGINXController::class, 'httpauth']);
            Route::post('cyrus-sasl', [API\V4\NGINXController::class, 'cyrussasl']);

            Route::get('metrics', [API\V4\MetricsController::class, 'metrics']);

            Route::post('policy/greylist', [API\V4\PolicyController::class, 'greylist']);
            Route::post('policy/ratelimit', [API\V4\PolicyController::class, 'ratelimit']);
            Route::post('policy/spf', [API\V4\PolicyController::class, 'senderPolicyFramework']);
            Route::post('policy/reception', [API\V4\PolicyController::class, 'reception']);
            Route::post('policy/submission', [API\V4\PolicyController::class, 'submission']);
            Route::post('policy/mail/filter', [API\V4\PolicyController::class, 'mailfilter']);
        }
    );
}
