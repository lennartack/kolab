<?php

namespace App\Http\Controllers\API\V4;

use App\Device;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceInfoResource;
use App\Http\Resources\PlanResource;
use App\Plan;
use App\Rules\SignupToken as SignupTokenRule;
use App\SignupToken;
use App\Utils;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DeviceController extends Controller
{
    /**
     * Claim a device.
     *
     * @param string $token Device secret token
     */
    public function claim(string $token): JsonResponse
    {
        if (strlen($token) > 191) {
            return $this->errorResponse(404);
        }

        $device = Device::where('hash', strtoupper($token))->first();

        if (empty($device)) {
            return $this->errorResponse(404);
        }

        $device->bindTo($this->guard()->user());

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.device-claim-success'),
        ]);
    }

    /**
     * Device information.
     *
     * @param string $token Device secret token
     *
     * @unauthenticated
     */
    public function info(string $token)
    {
        if (strlen($token) > 191) {
            return $this->errorResponse(404);
        }

        $device = Device::where('hash', $token)->first();

        if (!$device) {
            return $this->errorResponse(404);
        }

        return new DeviceInfoResource($device);
    }

    /**
     * List signup plans.
     *
     * @param string $token Device secret token
     *
     * @unauthenticated
     */
    public function plans(string $token): JsonResponse
    {
        if (strlen($token) > 191) {
            return $this->errorResponse(404);
        }

        $token = SignupToken::where('id', strtoupper($token))->first();

        if (empty($token)) {
            return $this->errorResponse(404);
        }

        // TODO: Return plans specified in the token
        $plans = Plan::withEnvTenantContext()->where('mode', Plan::MODE_TOKEN)
            ->orderByDesc('months')->orderByDesc('title')
            ->get();

        return response()->json([
            // List of signup plans
            'list' => PlanResource::collection($plans),
            // @var int Number of entries in the list
            'count' => count($plans),
            // @var bool Indicates that there are more entries available
            'hasMore' => false,
        ]);
    }

    /**
     * Signup a device.
     *
     * @param string $token Device secret token
     *
     * @unauthenticated
     */
    #[BodyParameter('plan', description: 'Plan title', type: 'string', required: true)]
    public function signup(Request $request, string $token)
    {
        $v = Validator::make($request->all(), ['plan' => ['required', 'string']]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        // Signup plan
        $plan = Plan::withEnvTenantContext()->where('title', $request->plan)->first();

        if (!$plan) {
            $errors = ['plan' => self::trans('validation.invalidvalue')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        $request->merge([
            'plan' => $plan,
            'token' => \strtoupper($token),
        ]);

        // Validate input
        $v = Validator::make(
            $request->all(),
            [
                'token' => ['required', 'string', new SignupTokenRule($plan)],
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', /* @var array */ 'errors' => $v->errors()], 422);
        }

        // TODO: Validate that the plan is device-only, don't accept a user plan here

        // Check if a device already exists
        if (Device::withTrashed()->where('hash', $token)->exists()) {
            return $this->errorResponse(500);
        }

        // TODO: Should we get the password from the device? Then we'd not have to return it back at the end
        $password = Utils::generatePassphrase();

        // Register a device
        $device = Device::signup($token, $plan, $password);

        // Auto-login the user (same as we do on a normal user signup)
        $response = AuthController::logonResponse($device->account, $password);

        // Let the device know email+password so it can use the API
        $response->credentials = [
            'email' => $device->account->email,
            'password' => $password,
        ];

        return $response;
    }
}
