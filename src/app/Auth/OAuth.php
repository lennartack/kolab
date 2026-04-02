<?php

namespace App\Auth;

use App\Http\Controllers\Controller;
use App\Support\Facades\Roundcube;
use App\User;
use App\Utils;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ServerRequestInterface;

class OAuth
{
    /**
     * Approval request for the oauth authorization endpoint
     *
     * The implementation is based on Laravel\Passport\Http\Controllers\AuthorizationController
     *
     * @param User                   $user       Authenticating user
     * @param ServerRequestInterface $psrRequest PSR request
     * @param Request                $request    The API request
     * @param AuthorizationServer    $server     Authorization server
     * @param bool                   $use_cache  Cache the approval state
     *
     * @return JsonResponse
     */
    public static function approve(
        User $user,
        ServerRequestInterface $psrRequest,
        Request $request,
        AuthorizationServer $server,
        bool $use_cache = true
    ) {
        $clientId = $request->input('client_id');

        try {
            if ($request->response_type != 'code') {
                throw new \Exception('Invalid response_type');
            }

            $cacheKey = "oauth-seen-{$user->id}-{$clientId}";

            // OpenID handler reads parameters from the request query string (GET)
            $request->query->replace($request->input());

            // OAuth2 server's code also expects GET parameters, but we're using POST here
            $psrRequest = $psrRequest->withQueryParams($request->input());

            $authRequest = $server->validateAuthorizationRequest($psrRequest);

            // Check if the client was approved before (in last x days)
            if ($clientId && $use_cache && $request->ifSeen) {
                $client = PassportClient::find($clientId);

                if ($client) {
                    // System clients are trusted, don't need approval
                    if (!$client->user_id) {
                        $use_cache = false;
                    } elseif (!Cache::has($cacheKey)) {
                        throw new \Exception('Not seen yet');
                    }
                }
            }

            // TODO I'm not sure if we should still execute this to deny the request
            $authRequest->setUser(new \Laravel\Passport\Bridge\User($user->getAuthIdentifier()));
            $authRequest->setAuthorizationApproved(true);

            // This will generate a 302 redirect to the redirect_uri with the generated authorization code
            $response = $server->completeAuthorizationRequest($authRequest, new Psr7Response());

            // Remember the approval for x days.
            // In this time we'll not show the UI form and we'll redirect automatically
            // TODO: If we wanted to give users ability to remove this "approved" state for a client,
            // we would have to store these records in SQL table. It would become handy especially
            // if we give users possibility to register external OAuth apps.
            if ($use_cache) {
                Cache::put($cacheKey, 1, now()->addDays(14));
            }
        } catch (OAuthServerException $e) {
            // Note: We don't want 401 or 400 codes here, use 422 which is used in our API
            $code = $e->getHttpStatusCode();
            $response = $e->getPayload();
            $response['redirectUrl'] = !empty($client) ? $client->redirect : $request->input('redirect_uri');

            return Controller::errorResponse($code < 500 ? 422 : 500, $e->getMessage(), $response);
        } catch (\Exception $e) {
            if (!empty($client)) {
                $scopes = preg_split('/\s+/', (string) $request->input('scope'));

                $claims = [];
                foreach (array_intersect($scopes, $client->allowed_scopes) as $claim) {
                    $claims[$claim] = Controller::trans("auth.claim.{$claim}");
                }

                return response()->json([
                    'status' => 'prompt',
                    'client' => [
                        'name' => $client->name,
                        'url' => $client->redirect,
                        'claims' => $claims,
                    ],
                ]);
            }

            $response = [
                'error' => $e->getMessage() == 'Invalid response_type' ? 'unsupported_response_type' : 'server_error',
                'redirectUrl' => $request->input('redirect_uri'),
            ];

            return Controller::errorResponse(422, Controller::trans('auth.error.invalidrequest'), $response);
        }

        return response()->json([
            'status' => 'success',
            // Client (e.g. webmail) location to redirect to
            'redirectUrl' => $response->getHeader('Location')[0],
        ]);
    }

    /**
     * Get the authenticated User information (using access token claims)
     *
     * @param User $user User
     */
    public static function userInfo(User $user): array
    {
        $response = [
            // Per OIDC spec. 'sub' must be always returned
            'sub' => $user->id,
        ];

        if ($user->tokenCan('email')) {
            $response['email'] = $user->email;
            $response['email_verified'] = $user->isActive();
            // At least synapse depends on a "settings" structure being available
            $response['settings'] = ['name' => $user->name()];
        }

        // TODO: Other claims (https://openid.net/specs/openid-connect-core-1_0.html#StandardClaims)
        // address: address
        // phone: phone_number and phone_number_verified
        // profile: name, family_name, given_name, middle_name, nickname, preferred_username,
        //    profile, picture, website, gender, birthdate, zoneinfo, locale, and updated_at

        return $response;
    }

    /**
     * Webmail Login-As session initialization (via SSO)
     *
     * @param User                   $user       The user to log in as
     * @param ServerRequestInterface $psrRequest PSR request
     * @param Request                $request    The API request
     * @param AuthorizationServer    $server     Authorization server
     *
     * @return JsonResponse
     */
    public static function loginAs(User $user, ServerRequestInterface $psrRequest, Request $request, AuthorizationServer $server)
    {
        // Use OAuth client for Webmail
        $client = PassportClient::where('name', 'Webmail SSO client')->whereNull('user_id')->first();

        if (!$client) {
            return Controller::errorResponse(404);
        }

        // Abuse the self::oauthApprove() handler to init the OAuth session (code)
        $request->merge([
            'client_id' => $client->id,
            'redirect_uri' => $client->redirect,
            'scope' => 'email openid auth.token',
            'state' => Utils::uuidStr(),
            'nonce' => Utils::uuidStr(),
            'response_type' => 'code',
            'ifSeen' => false,
        ]);

        $response = self::approve($user, $psrRequest, $request, $server, false);

        // Check status, on error remove the redirect url
        if ($response->status() != 200) {
            return Controller::errorResponse($response->status(), $response->getData()->error);
        }

        $url = $response->getData()->redirectUrl;

        // Store state+nonce in Roundcube database (for the kolab plugin)
        // for request origin validation and token validation there
        // Get the code from the URL
        parse_str(parse_url($url, \PHP_URL_QUERY), $query);

        Roundcube::cacheSet(
            'helpdesk.' . md5($query['code']),
            [
                'state' => $request->state,
                'nonce' => $request->nonce,
            ],
            30 // TTL
        );

        // Tell the kolab plugin that the request origin is helpdesk mode, it will read
        // the cache entry and make sure the token is accepted by Roundcube OAuth code.
        $response->setData([
            'redirectUrl' => $url . '&helpdesk=1',
            'status' => 'success',
        ]);

        return $response;
    }
}
