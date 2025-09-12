<?php

namespace App\Http\Controllers\API;

use App\Auth\OAuth;
use App\AuthAttempt;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthErrorResource;
use App\Http\Resources\AuthResource;
use App\Http\Resources\UserInfoResource;
use App\User;
use App\Utils;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Passport\RefreshTokenRepository;
use Laravel\Passport\TokenRepository;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    /**
     * Get user information.
     *
     * Note that the same information is by default included in the `auth/login` response.
     */
    public function info(): JsonResponse
    {
        $response = new UserInfoResource($this->guard()->user());

        return $response->response();
    }

    /**
     * Helper method for other controllers with user auto-logon
     * functionality
     *
     * @param User        $user         User model object
     * @param string      $password     Plain text password
     * @param string|null $secondFactor Second factor code if available
     */
    public static function logonResponse(User $user, string $password, ?string $secondFactor = null): JsonResponse
    {
        $mode = request()->mode; // have to be before we make a request below

        $proxyRequest = Request::create('/oauth/token', 'POST', [
            'username' => $user->email,
            'password' => $password,
            'grant_type' => 'password',
            'client_id' => \config('auth.proxy.client_id'),
            'client_secret' => \config('auth.proxy.client_secret'),
            'scope' => 'api',
            'secondfactor' => $secondFactor,
        ]);

        $proxyRequest->headers->set('X-Client-IP', request()->ip());

        $tokenResponse = app()->handle($proxyRequest);

        return self::respondWithToken($tokenResponse, $user, $mode);
    }

    /**
     * Log in a user.
     *
     * Returns an authentication token(s) and user information.
     *
     * @unauthenticated
     */
    public function login(Request $request): JsonResponse
    {
        $v = Validator::make(
            $request->all(),
            [
                'email' => 'required|min:3',
                'password' => 'required|min:1',
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            \Log::debug("[Auth] User not found on login: {$request->email}");
            return response()->json(['status' => 'error', 'message' => self::trans('auth.failed')], 401);
        }

        if ($user->role == User::ROLE_SERVICE) {
            \Log::debug("[Auth] Login with service account not allowed: {$request->email}");
            return response()->json(['status' => 'error', 'message' => self::trans('auth.failed')], 401);
        }

        return self::logonResponse($user, $request->password, $request->secondfactor);
    }

    /**
     * OAuth (SSO) authorization.
     *
     * * The user is authenticated via the regular login page
     * * We assume implicit consent in the Authorization page
     * * Ultimately we return an authorization code to the caller via the redirect_uri
     *
     * The implementation is based on Laravel\Passport\Http\Controllers\AuthorizationController
     *
     * @param ServerRequestInterface $psrRequest PSR request
     * @param Request                $request    The API request
     * @param AuthorizationServer    $server     Authorization server
     */
    public function oauthApprove(ServerRequestInterface $psrRequest, Request $request, AuthorizationServer $server): JsonResponse
    {
        $user = $this->guard()->user();

        return OAuth::approve($user, $psrRequest, $request, $server);
    }

    /**
     * Get the authenticated User information (using access token claims)
     */
    public function oauthUserInfo(): JsonResponse
    {
        $user = $this->guard()->user();

        $response = OAuth::userInfo($user);

        return response()->json($response);
    }

    /**
     * Get geo-location
     *
     * @unauthenticated
     */
    public function location(): JsonResponse
    {
        $ip = request()->ip();

        $response = [
            // Client IP address
            'ipAddress' => $ip,
            // Client country code (derived from the IP address)
            'countryCode' => Utils::countryForIP($ip, ''),
        ];

        return response()->json($response);
    }

    /**
     * Logout a user.
     *
     * Revokes the authentication token.
     */
    public function logout(): JsonResponse
    {
        $tokenId = $this->guard()->user()->token()->id;
        $tokenRepository = app(TokenRepository::class);
        $refreshTokenRepository = app(RefreshTokenRepository::class);

        // Revoke an access token...
        $tokenRepository->revokeAccessToken($tokenId);

        // Revoke all of the token's refresh tokens...
        $refreshTokenRepository->revokeRefreshTokensByAccessTokenId($tokenId);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('auth.logoutsuccess'),
        ]);
    }

    /**
     * Refresh a session token.
     */
    public function refresh(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            // Request user information in the response
            'info' => 'bool',
            // A refresh token
            'refresh_token' => 'string|required',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $user = $request->info ? $this->guard()->user() : null;

        $proxyRequest = Request::create('/oauth/token', 'POST', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $request->refresh_token,
            'client_id' => \config('auth.proxy.client_id'),
            'client_secret' => \config('auth.proxy.client_secret'),
        ]);

        $tokenResponse = app()->handle($proxyRequest);

        return self::respondWithToken($tokenResponse, $user);
    }

    /**
     * Get the token array structure.
     *
     * @param Response $tokenResponse the response containing the token
     * @param ?User    $user          The user being authenticated
     * @param ?bool    $mode          Response mode: 'fast' - return minimum set of user data
     */
    protected static function respondWithToken($tokenResponse, $user = null, $mode = null): JsonResponse
    {
        $data = json_decode($tokenResponse->getContent());

        if ($tokenResponse->getStatusCode() != 200) {
            if (isset($data->error) && $data->error == 'secondfactor' && isset($data->error_description)) {
                $errors = ['secondfactor' => $data->error_description];
                return response()->json(['status' => 'error', 'errors' => $errors], 422);
            }

            $response = new AuthErrorResource(null);
            $response->message = self::trans('auth.failed');

            if (isset($data->error) && $data->error == AuthAttempt::REASON_PASSWORD_EXPIRED) {
                $response->message = $data->error_description;
                $response->password_expired = true;

                if ($user) {
                    // At this point we know the password is correct, but expired.
                    // So, it should be safe to send the user ID back. It will be used
                    // for the new password policy checks.
                    $response->user_id = $user->id;
                }
            }

            return response()->json($response, 401);
        }

        $response = new AuthResource($data);

        if ($user) {
            if ($mode == 'fast') {
                $response->user_id = $user->id;
            } else {
                $response->withUserInfo(new UserInfoResource($user));
            }
        }

        return $response->response();
    }
}
