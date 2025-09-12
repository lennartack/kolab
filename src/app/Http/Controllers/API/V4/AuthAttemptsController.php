<?php

namespace App\Http\Controllers\API\V4;

use App\AuthAttempt;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthAttemptResource;
use App\Utils;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthAttemptsController extends Controller
{
    /**
     * Confirm an authentication attempt.
     *
     * @param string $id Authentication attempt identifier
     */
    public function confirm($id): JsonResponse
    {
        $authAttempt = AuthAttempt::find($id);
        if (!$authAttempt) {
            return $this->errorResponse(404);
        }

        $user = $this->guard()->user();
        if ($user->id != $authAttempt->user_id) {
            return $this->errorResponse(403);
        }

        \Log::debug("Confirm on {$authAttempt->id}");
        $authAttempt->accept();

        return response()->json([], 200);
    }

    /**
     * Deny an authentication attempt.
     *
     * @param string $id Authentication attempt identifier
     */
    public function deny($id): JsonResponse
    {
        $authAttempt = AuthAttempt::find($id);
        if (!$authAttempt) {
            return $this->errorResponse(404);
        }

        $user = $this->guard()->user();
        if ($user->id != $authAttempt->user_id) {
            return $this->errorResponse(403);
        }

        \Log::debug("Deny on {$authAttempt->id}");
        $authAttempt->deny();

        return response()->json([], 200);
    }

    /**
     * Authentication attempt information.
     *
     * @param string $id Authentication attempt identifier
     */
    public function details($id): JsonResponse
    {
        $authAttempt = AuthAttempt::find($id);
        if (!$authAttempt) {
            return $this->errorResponse(404);
        }

        $user = $this->guard()->user();
        if ($user->id != $authAttempt->user_id) {
            return $this->errorResponse(403);
        }

        return response()->json([
            'status' => 'success',
            // User email address
            'username' => $user->email,
            // Country code (from the IP address of the authentication attempt)
            'country' => Utils::countryForIP($authAttempt->ip),
            // Authentication attempt information
            'entry' => new AuthAttemptResource($authAttempt),
        ]);
    }

    /**
     * List of authentication attempts.
     *
     * All authentication attempts from the current user clients.
     * The list page contains up to 10 entries.
     */
    #[QueryParameter('page', description: 'Page number', type: 'int', default: 1)]
    public function index(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        $pageSize = 10;
        $page = (int) ($request->input('page')) ?: 1;
        $hasMore = false;

        $result = AuthAttempt::where('user_id', $user->id)
            ->orderBy('updated_at', 'desc')
            ->limit($pageSize + 1)
            ->offset($pageSize * ($page - 1))
            ->get();

        if (count($result) > $pageSize) {
            $result->pop();
            $hasMore = true;
        }

        // TODO: Change the response format to include 'list', 'count', 'hasMore' properties.

        return response()->json(AuthAttemptResource::collection($result));
    }
}
