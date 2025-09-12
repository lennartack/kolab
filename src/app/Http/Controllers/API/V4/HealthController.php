<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Liveness probe
     */
    public function liveness(): JsonResponse
    {
        $response = response()->json('success', 200);
        $response->noLogging = true; // @phpstan-ignore-line
        return $response;
    }

    /**
     * Readiness probe
     */
    public function readiness(): JsonResponse
    {
        $response = response()->json('success', 200);
        $response->noLogging = true; // @phpstan-ignore-line
        return $response;
    }
}
