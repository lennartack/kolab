<?php

namespace App\Http\Controllers\API;

use App\Auth\OAuth;
use App\Http\Controllers\Controller;
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
     * Get the authenticated User
     *
     * @return JsonResponse
     */
    public function info()
    {
        $user = $this->guard()->user();

        if (!empty(request()->input('refresh'))) {
            return $this->refreshAndRespond(request(), $user);
        }

        $response = V4\UsersController::userResponse($user);

        return response()->json($response);
    }

    /**
     * Helper method for other controllers with user auto-logon
     * functionality
     *
     * @param User        $user         User model object
     * @param string      $password     Plain text password
     * @param string|null $secondFactor Second factor code if available
     */
    public static function logonResponse(User $user, string $password, ?string $secondFactor = null)
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
     * Get an oauth token via given credentials.
     *
     * @param Request $request the API request
     *
     * @return JsonResponse
     */
    public function login(Request $request)
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
     * Approval request for the oauth authorization endpoint
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
     *
     * @return JsonResponse
     */
    public function oauthApprove(ServerRequestInterface $psrRequest, Request $request, AuthorizationServer $server)
    {
        $user = $this->guard()->user();

        return OAuth::approve($user, $psrRequest, $request, $server);
    }

    /**
     * Get the authenticated User information (using access token claims)
     *
     * @return JsonResponse
     */
    public function oauthUserInfo()
    {
        $user = $this->guard()->user();

        $response = OAuth::userInfo($user);

        return response()->json($response);
    }

    /**
     * Get the user (geo) location
     *
     * @return JsonResponse
     */
    public function location()
    {
        $ip = request()->ip();

        $response = [
            'ipAddress' => $ip,
            'countryCode' => Utils::countryForIP($ip, ''),
        ];

        return response()->json($response);
    }

    /**
     * Log the user out (Invalidate the token)
     *
     * @return JsonResponse
     */
    public function logout()
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
     * Refresh a token.
     *
     * @return JsonResponse
     */
    public function refresh(Request $request)
    {
        return self::refreshAndRespond($request);
    }

    /**
     * Refresh the token and respond with it.
     *
     * @param Request $request the API request
     * @param ?User   $user    The user being authenticated
     *
     * @return JsonResponse
     */
    protected static function refreshAndRespond(Request $request, $user = null)
    {
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
     *
     * @return JsonResponse
     */
    protected static function respondWithToken($tokenResponse, $user = null, $mode = null)
    {
        $data = json_decode($tokenResponse->getContent());

        if ($tokenResponse->getStatusCode() != 200) {
            if (isset($data->error) && $data->error == 'secondfactor' && isset($data->error_description)) {
                $errors = ['secondfactor' => $data->error_description];
                return response()->json(['status' => 'error', 'errors' => $errors], 422);
            }

            \Log::warning("Failed to request a token: " . (string) $tokenResponse);
            return response()->json(['status' => 'error', 'message' => self::trans('auth.failed')], 401);
        }

        $response = [];

        if ($user) {
            if ($mode == 'fast') {
                $response['id'] = $user->id;
            } else {
                $response = V4\UsersController::userResponse($user);
            }
        }

        $response['status'] = 'success';
        $response['access_token'] = $data->access_token;
        $response['refresh_token'] = $data->refresh_token;
        $response['token_type'] = 'bearer';
        $response['expires_in'] = $data->expires_in;

        return response()->json($response);
    }
}
