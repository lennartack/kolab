<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class SupportController extends Controller
{
    /**
     * Submit support form.
     *
     * @unauthenticated
     */
    public function request(Request $request): JsonResponse
    {
        // Check required fields
        $v = Validator::make($request->all(), $rules = [
            // User identifier
            'user' => 'string|nullable|max:256',
            // User name
            'name' => 'string|nullable|max:256',
            // Contact email address
            'email' => 'required|email',
            // Request summary
            'summary' => 'required|string|max:512',
            // Request body
            'body' => 'required|string',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $params = $request->only(array_keys($rules));

        $to = \config('app.support_email');

        if (empty($to)) {
            \Log::error("Failed to send a support request. SUPPORT_EMAIL not set");
            return $this->errorResponse(500, self::trans('app.support-request-error'));
        }

        $content = sprintf(
            "ID: %s\nName: %s\nWorking email address: %s\nSubject: %s\n\n%s\n",
            $params['user'] ?? '',
            $params['name'] ?? '',
            $params['email'],
            $params['summary'],
            $params['body'],
        );

        Mail::raw($content, static function ($message) use ($params, $to) {
            // Remove the global reply-to addressee
            $message->getHeaders()->remove('Reply-To');

            $message->to($to)
                ->from($params['email'], $params['name'] ?? null)
                ->replyTo($params['email'], $params['name'] ?? null)
                ->subject($params['summary']);
        });

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.support-request-success'),
        ]);
    }
}
