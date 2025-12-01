<?php

use App\Http\Controllers;
use App\Utils;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers as PassportControllers;

Route::get('204', static function () {
    return response()->noContent();
});

// We can handle every URL with the default action because
// we have client-side router (including 404 error handler).
// This way we don't have to define any "deep link" routes here.
Route::group(
    [
        // 'domain' => \config('app.website_domain'),
    ],
    static function () {
        Route::get('content/page/{page}', [Controllers\ContentController::class, 'pageContent'])
            ->where('page', '(.*)');
        Route::get('content/faq/{page}', [Controllers\ContentController::class, 'faqContent'])
            ->where('page', '(.*)');

        Route::fallback([Utils::class, 'defaultView']);
    }
);

Route::group(
    [
        'prefix' => 'oauth',
    ],
    static function () {
        // We manually specify a subset of endpoints from https://github.com/laravel/passport/blob/11.x/routes/web.php
        // after having disabled automatic routes via Passport::ignoreRoutes()
        Route::post('/token', [PassportControllers\AccessTokenController::class, 'issueToken'])
            ->name('passport.token'); // needed for .well-known/openid-configuration handler

        Route::middleware(['web', 'auth'])->group(static function () {
            Route::get('/tokens', [PassportControllers\AuthorizedAccessTokenController::class, 'forUser'])
                ->name('passport.tokens.index');

            Route::delete('/tokens/{token_id}', [PassportControllers\AuthorizedAccessTokenController::class, 'destroy'])
                ->name('passport.tokens.destroy');
        });

        // TODO: Enable CORS on this endpoint, it is "SHOULD" in OIDC spec.
        // TODO: More scopes e.g. profile
        // TODO: This should be both GET and POST per OIDC spec. GET is recommended though.
        Route::get('/userinfo', [Controllers\API\AuthController::class, 'oauthUserInfo'])
            ->middleware(['auth:api', 'scope:email'])
            ->name('openid.userinfo'); // needed for .well-known/openid-configuration handler

        Route::get('/authorize', [Utils::class, 'defaultView'])
            ->name('passport.authorizations.authorize'); // needed for .well-known/openid-configuration handler
    }
);

Route::group(
    [
        'prefix' => '.well-known',
    ],
    static function () {
        // .well-known/openid-configuration is handled by an external package (see config/openid.php)
        Route::get('/mta-sts.txt', [Controllers\WellKnownController::class, 'mtaSts']);
    }
);

if (\config('app.with_webdav')) {
    Controllers\DAVController::registerRoutes();
}

Controllers\DiscoveryController::registerRoutes();
