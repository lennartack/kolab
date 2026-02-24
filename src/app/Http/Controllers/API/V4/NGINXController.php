<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NGINXController extends Controller
{
    /**
     * Authorize with the provided credentials.
     *
     * @param string $login    The login name
     * @param string $password The password
     *
     * @return User The user
     *
     * @throws \Exception if the authorization fails
     */
    private function authorizeRequestCredentialsOnly($login, $password)
    {
        if (empty($login)) {
            throw new \Exception("Empty login");
        }

        if (empty($password)) {
            throw new \Exception("Empty password");
        }

        $result = User::findAndAuthenticate($login, $password, null, false);

        if (empty($result['user'])) {
            throw new \Exception($result['errorMessage'] ?? "Unknown error");
        }

        return $result['user'];
    }

    /**
     * Authorize with the provided credentials.
     *
     * @param string $login    The login name
     * @param string $password The password
     * @param string $clientIP The client ip
     *
     * @return User The user
     *
     * @throws \Exception if the authorization fails
     */
    private function authorizeRequest($login, $password, $clientIP)
    {
        if (empty($login)) {
            throw new \Exception("Empty login");
        }

        if (empty($password)) {
            throw new \Exception("Empty password");
        }

        if (empty($clientIP)) {
            throw new \Exception("No client ip");
        }

        $result = User::findAndAuthenticate($login, $password, $clientIP);

        if (empty($result['user'])) {
            throw new \Exception($result['errorMessage'] ?? "Unknown error");
        }

        // TODO: validate the user's domain is A-OK (active, confirmed, not suspended, ldapready)
        // TODO: validate the user is A-OK (active, not suspended, ldapready, imapready)
        // TODO: Apply some sort of limit for Auth-Login-Attempt -- docs say it is the number of
        //       attempts over the same authAttempt.

        return $result['user'];
    }

    /**
     * Convert domain.tld\username into username@domain for activesync
     *
     * @param string $username the original username
     *
     * @return string The username in canonical form
     */
    private function normalizeUsername($username)
    {
        $usernameParts = explode("\\", $username);

        if (count($usernameParts) == 2) {
            $username = $usernameParts[1];
            if (!strpos($username, '@') && !empty($usernameParts[0])) {
                $username .= '@' . $usernameParts[0];
            }
        }

        return $username;
    }

    private function authenticateHTTP($user = null)
    {
        $backend = "default";
        if ($user && (bool) $user->getSetting('kolabobjects_storage') || \config('app.kolabobjects_storage')) {
            $backend = "alternative";
        }
        $response = response('')->withHeaders(
            [
                "davbackend" => $backend,
            ]
        );
        return $response;
    }

    /**
     * Authentication request from the ngx_http_auth_request_module
     */
    public function httpauth(Request $request): Response
    {
        /*
         * Php-Auth-Pw:               simple123
         * Php-Auth-User:             john@kolab.org
         * Sec-Fetch-Dest:            document
         * Sec-Fetch-Mode:            navigate
         * Sec-Fetch-Site:            cross-site
         * Sec-Gpc:                   1
         * Upgrade-Insecure-Requests: 1
         * User-Agent:                Mozilla/5.0 (X11; Fedora; Linux x86_64; rv:93.0) Gecko/20100101 Firefox/93.0
         * X-Forwarded-For:           31.10.153.58
         * X-Forwarded-Proto:         https
         * X-Original-Uri:            /iRony/
         * X-Real-Ip:                 31.10.153.58
         */

        $username = $this->normalizeUsername($request->headers->get('Php-Auth-User', ''));
        $password = $request->headers->get('Php-Auth-Pw', null);
        $ip = $request->headers->get('X-Real-Ip', null);

        if (empty($username)) {
            // Allow unauthenticated requests
            return $this->authenticateHTTP();
        }

        if (empty($password)) {
            \Log::debug("Authentication attempt failed: Empty password provided.");
            return response("", 401);
        }

        try {
            $user = $this->authorizeRequest($username, $password, $ip);
        } catch (\Exception $e) {
            \Log::debug("Authentication attempt failed: {$e->getMessage()}");
            return response("", 403);
        }

        \Log::debug("Authentication attempt succeeded");
        return $this->authenticateHTTP($user);
    }

    /**
     * Authentication request from the cyrus sasl
     */
    public function cyrussasl(Request $request): Response
    {
        $data = $request->getContent();

        // Assumes "%u %r %p" as form data in the cyrus sasl config file
        $array = explode(' ', rawurldecode($data));
        if (count($array) != 3) {
            \Log::debug("Authentication attempt failed: invalid data provided.");
            return response("", 403);
        }

        $username = $array[0];
        $realm = $array[1];
        $password = $array[2];

        if (!empty($realm)) {
            $username = "{$username}@{$realm}";
        }

        try {
            $this->authorizeRequestCredentialsOnly($username, $password);
        } catch (\Exception $e) {
            \Log::debug("Authentication attempt failed for {$username}: {$e->getMessage()}");
            return response('', 403);
        }

        \Log::debug("Authentication attempt succeeded for {$username}");
        return response('');
    }

    /**
     * Authentication request.
     *
     * @todo: Separate IMAP(+STARTTLS) from IMAPS, same for SMTP/submission. =>
     *   I suppose that's not necessary given that we have the information avialable in the headers?
     */
    public function authenticate(Request $request): Response
    {
        /*
         *  Auth-Login-Attempt: 1
         *  Auth-Method:        plain
         *  Auth-Pass:          simple123
         *  Auth-Protocol:      imap
         *  Auth-Ssl:           on
         *  Auth-User:          john@kolab.org
         *  Client-Ip:          127.0.0.1
         *  Host:               127.0.0.1
         *
         *  Auth-SSL: on
         *  Auth-SSL-Verify: SUCCESS
         *  Auth-SSL-Subject: /CN=example.com
         *  Auth-SSL-Issuer: /CN=example.com
         *  Auth-SSL-Serial: C07AD56B846B5BFF
         *  Auth-SSL-Fingerprint: 29d6a80a123d13355ed16b4b04605e29cb55a5ad
         */

        $password = $request->headers->get('Auth-Pass', null);
        $username = $request->headers->get('Auth-User', null);
        $ip = $request->headers->get('Client-Ip', null);

        try {
            $user = $this->authorizeRequest($username, $password, $ip);
        } catch (\Exception $e) {
            return $this->byebye($request, $e->getMessage());
        }

        // All checks passed
        switch ($request->headers->get('Auth-Protocol')) {
            case 'imap':
                return $this->authenticateIMAP($request, $password);
            case 'smtp':
                return $this->authenticateSMTP($request, $password);
            default:
                return $this->byebye($request, "unknown protocol in request");
        }
    }

    /**
     * Authentication request for roundcube imap.
     */
    public function authenticateRoundcube(Request $request): Response
    {
        /*
         *  Auth-Login-Attempt: 1
         *  Auth-Method:        plain
         *  Auth-Pass:          simple123
         *  Auth-Protocol:      imap
         *  Auth-Ssl:           on
         *  Auth-User:          john@kolab.org
         *  Client-Ip:          127.0.0.1
         *  Host:               127.0.0.1
         *
         *  Auth-SSL: on
         *  Auth-SSL-Verify: SUCCESS
         *  Auth-SSL-Subject: /CN=example.com
         *  Auth-SSL-Issuer: /CN=example.com
         *  Auth-SSL-Serial: C07AD56B846B5BFF
         *  Auth-SSL-Fingerprint: 29d6a80a123d13355ed16b4b04605e29cb55a5ad
         */

        $password = $request->headers->get('Auth-Pass', null);
        $username = $request->headers->get('Auth-User', null);
        $ip = $request->headers->get('Proxy-Protocol-Addr', null);

        try {
            $user = $this->authorizeRequest($username, $password, $ip);
        } catch (\Exception $e) {
            return $this->byebye($request, $e->getMessage());
        }

        // All checks passed
        switch ($request->headers->get('Auth-Protocol')) {
            case 'imap':
                return $this->authenticateIMAP($request, $password);
            default:
                return $this->byebye($request, "unknown protocol in request");
        }
    }

    /**
     * Create an imap authentication response.
     *
     * @param Request $request  the API request
     * @param string  $password the password to include in the response
     */
    private function authenticateIMAP(Request $request, $password): Response
    {
        $response = response('')->withHeaders(
            [
                "Auth-Status" => "OK",
                "Auth-Server" => gethostbyname(\config('services.imap.host')),
                "Auth-Port" => \config('services.imap.imap_port'),
                "Auth-Pass" => $password,
            ]
        );

        return $response;
    }

    /**
     * Create an smtp authentication response.
     *
     * @param Request $request  the API request
     * @param string  $password the password to include in the response
     */
    private function authenticateSMTP(Request $request, $password): Response
    {
        $response = response('')->withHeaders(
            [
                "Auth-Status" => "OK",
                "Auth-Server" => gethostbyname(\config('services.smtp.host')),
                "Auth-Port" => \config('services.smtp.port'),
                "Auth-Pass" => $password,
            ]
        );

        return $response;
    }

    /**
     * Create a failed-authentication response.
     *
     * @param Request $request the API request
     * @param string  $reason  the reason for the failure
     */
    private function byebye(Request $request, $reason = null): Response
    {
        \Log::debug("Byebye: {$reason}");

        $response = response('')->withHeaders(
            [
                "Auth-Status" => "authentication failure",
                "Auth-Wait" => 3,
            ]
        );

        return $response;
    }
}
