<?php

namespace App\Http\Controllers\API\V4;

use App\Device;
use App\Http\Controllers\Controller;
use App\SignupToken;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    /**
     * User claims the device ownership.
     *
     * @param string $hash Device secret identifier
     *
     * @return JsonResponse The response
     */
    public function claim(string $hash)
    {
        if (strlen($hash) != 64) {
            return $this->errorResponse(404);
        }

        $device = Device::where('hash', $hash)->first();

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
     * Get the device information.
     *
     * @param string $hash Device secret identifier
     *
     * @return JsonResponse The response
     *
     * @unauthenticated
     */
    public function info(string $hash)
    {
        if (strlen($hash) != 64) {
            return $this->errorResponse(404);
        }

        $device = Device::where('hash', $hash)->first();

        // Register a device
        if (!$device) {
            // Only possible if a signup token exists?
            if (!SignupToken::where('id', $hash)->exists()) {
                return $this->errorResponse(404);
            }

            $device = Device::register($hash);
        }

        $response = $device->info();

        return response()->json($response);
    }
}
