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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PolicyController extends Controller
{
    /**
     * Validate the password regarding the defined policies.
     *
     * @return JsonResponse
     */
    public function checkPassword(Request $request)
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
            'status' => count($passed) == count($status) ? 'success' : 'error',
            'list' => array_values($status),
            'count' => count($status),
        ]);
    }

    /**
     * Take a greylist policy request
     *
     * @return JsonResponse
     */
    public function greylist()
    {
        $response = Greylist::handle(\request()->input());

        return $response->jsonResponse();
    }

    /**
     * Fetch the account policies for the current user account.
     * The result includes all supported policy rules.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
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
            'password' => array_values($password_policy),
            'mailDelivery' => $mail_delivery_policy,
            'config' => $policy_config,
        ]);
    }

    /**
     * SMTP Content Filter
     *
     * @param Request $request the API request
     *
     * @return Response The response
     */
    public function mailfilter(Request $request)
    {
        return Mailfilter::handle($request);
    }

    /*
     * Apply a sensible rate limitation to a request.
     *
     * @return JsonResponse
     */
    public function ratelimit()
    {
        $response = RateLimit::handle(\request()->input());

        return $response->jsonResponse();
    }

    /*
     * Apply the sender policy framework to a request.
     *
     * @return JsonResponse
     */
    public function senderPolicyFramework()
    {
        $response = SPF::handle(\request()->input());

        return $response->jsonResponse();
    }

    /*
     * Validate sender/recipients in an SMTP submission request.
     *
     * @return JsonResponse
     */
    public function submission()
    {
        $response = SmtpAccess::submission(\request()->input());

        return $response->jsonResponse();
    }
}
