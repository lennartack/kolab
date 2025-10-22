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
        $user = $this->guard()->user();

        if ($device) {
            $device_wallet = $device->wallet();
            $user_wallet = $user->wallets()->first();

            // Does the existing device already belong to this user?
            if ($device_wallet && $device_wallet->id == $user_wallet->id) {
                response()->json([
                    'status' => 'success',
                    'message' => self::trans('app.device-claim-success'),
                ]);
            }
        } else {
            if (!SignupToken::where('id', strtoupper($token))->exists()) {
                return $this->errorResponse(404);
            }
        }

        $device = Device::claim($token, $user);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.device-claim-success'),
            'device' => new DeviceInfoResource($device),
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
    #[BodyParameter('plan', description: 'Plan title', type: 'string')]
    public function signup(Request $request, string $token)
    {
        // Signup plan
        if ($request->plan) {
            $plan = Plan::withEnvTenantContext()->where('title', $request->plan)->first();
        } else {
            $plan = Device::defaultPlan($token, true);
        }

        if (!$plan) {
            $errors = ['plan' => self::trans('validation.invalidvalue')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // Validate token (needed when using non-default plan)
        if ($request->plan) {
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
        }

        // TODO: Validate that the plan is device-only, don't accept a user plan here

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

        $response->device = new DeviceInfoResource($device);

        return $response;
    }

    /**
     * Unclaim a device.
     *
     * @param string $token Device secret token
     */
    public function unclaim(string $token): JsonResponse
    {
        if (strlen($token) > 191) {
            return $this->errorResponse(404);
        }

        $device = Device::where('hash', strtoupper($token))->first();

        if (!$device) {
            return $this->errorResponse(404);
        }

        $user = $this->guard()->user();

        if (!$user->canDelete($device)) {
            return $this->errorResponse(403);
        }

        $device->delete();

        // TODO: Remove the role=device user account that owns the device?

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.device-unclaim-success'),
        ]);
    }
}
