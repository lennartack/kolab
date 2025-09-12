<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\Controller;
use App\Policy\Greylist;
use App\Policy\Mailfilter;
use App\Policy\Password;
use App\Policy\RateLimit;
use App\Policy\SmtpAccess;
use App\Policy\SPF;
use App\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PolicyController extends Controller
{
    /**
     * Validate the password regarding the defined policies.
     *
     * @unauthenticated
     */
    #[BodyParameter('user', description: 'User identifier', type: 'string')]
    #[BodyParameter('password', description: 'User password', type: 'string', required: true)]
    public function checkPassword(Request $request): JsonResponse
    {
        $userId = $request->input('user');
        $user = !empty($userId) ? User::find($userId) : null;

        // Check the password
        $status = Password::checkPolicy($request->input('password'), $user, $user ? $user->walletOwner() : null);

        $passed = array_filter(
            $status,
            static function ($rule) {
                return !empty($rule['status']);
            }
        );

        return response()->json([
            // Policy check status
            'status' => count($passed) == count($status) ? 'success' : 'error',
            // @var array Policy check result by rule
            'list' => array_values($status),
            // @var int Number of rules in the result list
            'count' => count($status),
        ]);
    }

    /**
     * Take a greylist policy request
     */
    public function greylist(): JsonResponse
    {
        $response = Greylist::handle(\request()->input());

        return $response->jsonResponse();
    }

    /**
     * Fetch the account policies for the current user account.
     * The result includes all supported policy rules.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        $owner = $user->walletOwner();

        if (!$user->canDelete($owner)) {
            return $this->errorResponse(403);
        }

        $config = $owner->getConfig();
        $policy_config = [];

        // Get the password policies
        $password_policy = Password::rules($owner, true);
        $policy_config['max_password_age'] = $config['max_password_age'];

        // Get the mail delivery policies
        $mail_delivery_policy = ['greylist_policy'];
        $policy_config['greylist_policy'] = $config['greylist_policy'];

        if (config('app.with_mailfilter')) {
            foreach (['itip_policy', 'externalsender_policy', 'externalsender_policy_domains'] as $name) {
                $mail_delivery_policy[] = $name;
                $policy_config[$name] = $config[$name];
            }
        }

        return response()->json([
            // @var array Password policies
            'password' => array_values($password_policy),
            // @var array Mail delivery policies
            'mailDelivery' => $mail_delivery_policy,
            // @var array Current account configuration
            'config' => $policy_config,
        ]);
    }

    /**
     * SMTP Content Filter
     */
    public function mailfilter(Request $request): Response|StreamedResponse
    {
        return Mailfilter::handle($request);
    }

    // Apply a sensible rate limitation to a request.
    public function ratelimit(): JsonResponse
    {
        $response = RateLimit::handle(\request()->input());

        return $response->jsonResponse();
    }

    /**
     * Validate a mail reception request (includes greylisting)
     */
    public function reception(): JsonResponse
    {
        $response = SmtpAccess::reception(\request()->input());

        return $response->jsonResponse();
    }

    // Apply the sender policy framework to a request.
    public function senderPolicyFramework(): JsonResponse
    {
        $response = SPF::handle(\request()->input());

        return $response->jsonResponse();
    }

    // Validate sender/recipients in an SMTP submission request.
    public function submission(): JsonResponse
    {
        $response = SmtpAccess::submission(\request()->input());

        return $response->jsonResponse();
    }
}
