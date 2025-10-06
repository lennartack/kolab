<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthResource;
use App\Jobs\Mail\PasswordResetJob;
use App\Rules\Password;
use App\User;
use App\VerificationCode;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Password reset API
 */
class PasswordResetController extends Controller
{
    /**
     * Initialize password reset via user's external email
     *
     * Verifies user email, creates a verification code and initiates a job to send an email message.
     *
     * @unauthenticated
     */
    public function init(Request $request): JsonResponse
    {
        // Check required fields
        $v = Validator::make($request->all(), ['email' => 'required|email']);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        // Find a user by email
        $user = User::findByEmail($request->email);

        if (!$user) {
            $errors = ['email' => self::trans('validation.usernotexists')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        if (!$user->getSetting('external_email')) {
            $errors = ['email' => self::trans('validation.noextemail')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // Geo-lockin check
        if (!$user->validateLocation($request->ip())) {
            // FIXME: Or maybe we should just throw some more generic error response/code?
            $errors = ['email' => self::trans('validation.geolockinerror')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // Generate the verification code
        $code = new VerificationCode(['mode' => VerificationCode::MODE_PASSWORD]);
        $user->verificationCodes()->save($code);

        // Send email/sms message
        PasswordResetJob::dispatch($code);

        return response()->json([
            'status' => 'success',
            // Verification code identifier
            'code' => $code->code,
        ]);
    }

    /**
     * Validation of the verification code.
     *
     * @unauthenticated
     */
    public function verify(Request $request): JsonResponse
    {
        // Validate the request args
        $v = Validator::make(
            $request->all(),
            [
                // Verification code identifier
                'code' => 'required',
                // Validation code secret
                'short_code' => 'required',
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        // Validate the verification code
        $code = VerificationCode::where('code', $request->code)->where('active', true)
            ->where('mode', VerificationCode::MODE_PASSWORD)->first();

        if (empty($code) || !$code->codeValidate($request->short_code)) {
            $errors = ['short_code' => self::trans('validation.verificationcodeinvalid')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // For last-step remember the code object, so we can delete it
        // with single SQL query (->delete()) instead of two (::destroy())
        $request->code = $code;

        return response()->json([
            'status' => 'success',
            // @var int User identifier
            // We need user ID for e.g. password policy checks
            'userId' => $code->user_id,
        ]);
    }

    /**
     * Password reset (using an email verification code)
     *
     * On success user will be auto-logged-in with a response same as for `auth/login` call.
     *
     * @unauthenticated
     */
    #[BodyParameter('secondfactor', description: '2FA token (required if user enabled 2FA)', type: 'string')]
    public function reset(Request $request)
    {
        $v = $this->verify($request);
        if ($v->status() !== 200) {
            return $v;
        }

        $user = $request->code->user;

        // Validate the password
        $v = Validator::make(
            $request->all(),
            [
                // New password
                'password' => ['required', 'confirmed', new Password($user->walletOwner())],
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        return self::changeUserPassword($user, $request);
    }

    /**
     * Expired password change (using user credentials)
     *
     * On success user will be auto-logged-in with a response same as for `auth/login` call.
     *
     * @unauthenticated
     */
    #[BodyParameter('email', description: 'User email address', type: 'string', required: true)]
    #[BodyParameter('secondfactor', description: '2FA token (required if user enabled 2FA)', type: 'string')]
    public function resetExpired(Request $request)
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || $user->role == User::ROLE_SERVICE) {
            $auth_error = true;
        }

        // Validate the current password
        if (empty($auth_error) && $user->validatePassword($request->password, true) !== true) {
            $auth_error = true;
        }

        if (!empty($auth_error)) {
            $errors = ['password' => self::trans('auth.failed')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // Validate the passwords
        $v = Validator::make(
            $request->all(),
            [
                // New password
                'new_password' => ['required', 'confirmed', new Password($user->walletOwner())],
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $request->password = $request->new_password;

        return self::changeUserPassword($user, $request);
    }

    /**
     * Create a verification code for the current user.
     */
    public function codeCreate(Request $request): JsonResponse
    {
        // Generate the verification code
        $code = new VerificationCode();
        $code->mode = VerificationCode::MODE_PASSWORD;

        // These codes are valid for 24 hours
        $code->expires_at = now()->addHours(24);

        // The code is inactive until it is submitted via a different endpoint
        $code->active = false;

        $this->guard()->user()->verificationCodes()->save($code);

        return response()->json([
            'status' => 'success',
            // Verification code identifier
            'code' => $code->code,
            // Verification code secret
            'short_code' => $code->short_code,
            // Code expiration date-time
            'expires_at' => $code->expires_at->toDateTimeString(),
        ]);
    }

    /**
     * Delete a verification code.
     *
     * @param string $id Verification code identifier
     */
    public function codeDelete($id): JsonResponse
    {
        // Accept <short-code>-<code> input
        if (strpos($id, '-')) {
            $id = explode('-', $id)[1];
        }

        $code = VerificationCode::find($id);

        if (!$code) {
            return $this->errorResponse(404);
        }

        $current_user = $this->guard()->user();

        if (empty($code->user) || !$current_user->canUpdate($code->user)) {
            return $this->errorResponse(403);
        }

        $code->delete();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.password-reset-code-delete-success'),
        ]);
    }

    /**
     * Change the user password and log-in the user
     */
    private static function changeUserPassword(User $user, Request $request)
    {
        DB::beginTransaction();

        // Change the user password
        $user->password = $request->password;
        $user->save();

        // Note: If logonResponse() would not use a HTTP request, this whole code
        // could be possibly a bit simpler (no need for a DB transaction).
        $response = AuthController::logonResponse($user, $request->password, $request->secondfactor);

        if ($response instanceof AuthResource) {
            // Remove the verification code
            if ($request->code instanceof VerificationCode) {
                $request->code->delete();
            }

            DB::commit();

            // Add confirmation message to the 'success' response
            $response->message = self::trans('app.password-reset-success');
        } else {
            // If authentication failed (2FA or geo-lock), revert the password change
            DB::rollBack();
        }

        return $response;
    }
}
