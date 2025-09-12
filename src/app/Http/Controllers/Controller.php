<?php

namespace App\Http\Controllers;

use App\User;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;

class Controller extends BaseController
{
    use AuthorizesRequests;
    use DispatchesJobs;
    use ValidatesRequests;

    /**
     * Common error response builder for API (JSON) responses
     *
     * @param int    $code    Error code
     * @param string $message Error message
     * @param array  $data    Additional response data
     */
    public static function errorResponse(int $code, string $message = '', array $data = []): JsonResponse
    {
        $errors = [
            400 => "Bad request",
            401 => "Unauthorized",
            403 => "Access denied",
            404 => "Not found",
            405 => "Method not allowed",
            422 => "Input validation error",
            429 => "Too many requests",
            500 => "Internal server error",
        ];

        $response = [
            'status' => 'error',
            // @var string
            'message' => $message ?: ($errors[$code] ?? "Server error"),
        ];

        if (!empty($data)) {
            $response += $data;
        }

        return response()->json($response, $code);
    }

    /**
     * Check if current user has access to the specified object
     * by being an admin or existing in the same tenant context.
     *
     * @param ?object $object Model object
     */
    protected function checkTenant(?object $object = null): bool
    {
        if (empty($object)) {
            return false;
        }

        $user = $this->guard()->user();

        if ($user->role == User::ROLE_ADMIN) {
            return true;
        }

        return $object->tenant_id == $user->tenant_id;
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return Guard
     */
    protected function guard()
    {
        return Auth::guard();
    }

    /**
     * A wrapper for \trans() with theme localization support.
     *
     * @param string $label  Localization label
     * @param array  $params Translation parameters
     */
    public static function trans(string $label, array $params = []): string
    {
        $result = \trans("theme::{$label}", $params);
        if ($result === "theme::{$label}") {
            $result = \trans($label, $params);
        }

        return $result;
    }
}
