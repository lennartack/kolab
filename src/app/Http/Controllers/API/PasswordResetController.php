<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\Mail\PasswordResetJob;
use App\Rules\Password;
use App\User;
use App\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Password reset API
 */
class PasswordResetController extends Controller
{
    /**
     * Sends password reset code to the user's external email
     *
     * Verifies user email, sends verification email message.
     *
     * @param Request $request HTTP request
     *
     * @return JsonResponse JSON response
     */
    public function init(Request $request)
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
        $code = new VerificationCode(['mode' => 'password-reset']);
        $user->verificationcodes()->save($code);

        // Send email/sms message
        PasswordResetJob::dispatch($code);

        return response()->json(['status' => 'success', 'code' => $code->code]);
    }

    /**
     * Validation of the verification code.
     *
     * @param Request $request HTTP request
     *
     * @return JsonResponse JSON response
     */
    public function verify(Request $request)
    {
        // Validate the request args
        $v = Validator::make(
            $request->all(),
            [
                'code' => 'required',
                'short_code' => 'required',
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        // Validate the verification code
        $code = VerificationCode::where('code', $request->code)->where('active', true)->first();

        if (
            empty($code)
            || $code->isExpired()
            || $code->mode !== 'password-reset'
            || Str::upper($request->short_code) !== Str::upper($code->short_code)
        ) {
            $errors = ['short_code' => "The code is invalid or expired."];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // For last-step remember the code object, so we can delete it
        // with single SQL query (->delete()) instead of two (::destroy())
        $request->code = $code;

        return response()->json([
            'status' => 'success',
            // we need user's ID for e.g. password policy checks
            'userId' => $code->user_id,
        ]);
    }

    /**
     * Password reset (using an email verification code)
     *
     * @param Request $request HTTP request
     *
     * @return JsonResponse JSON response
     */
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
            ['password' => ['required', 'confirmed', new Password($user->walletOwner())]]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        return self::changeUserPassword($user, $request);
    }

    /**
     * Expired password change (using user credentials)
     *
     * @param Request $request HTTP request
     *
     * @return JsonResponse JSON response
     */
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
     *
     * @param Request $request HTTP request
     *
     * @return JsonResponse JSON response
     */
    public function codeCreate(Request $request)
    {
        // Generate the verification code
        $code = new VerificationCode();
        $code->mode = 'password-reset';

        // These codes are valid for 24 hours
        $code->expires_at = now()->addHours(24);

        // The code is inactive until it is submitted via a different endpoint
        $code->active = false;

        $this->guard()->user()->verificationcodes()->save($code);

        return response()->json([
            'status' => 'success',
            'code' => $code->code,
            'short_code' => $code->short_code,
            'expires_at' => $code->expires_at->toDateTimeString(),
        ]);
    }

    /**
     * Delete a verification code.
     *
     * @param string $id Code identifier
     *
     * @return JsonResponse The response
     */
    public function codeDelete($id)
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

        if ($response->status() == 200) {
            // Remove the verification code
            if ($request->code instanceof VerificationCode) {
                $request->code->delete();
            }

            DB::commit();

            // Add confirmation message to the 'success' response
            $data = $response->getData(true);
            $data['message'] = self::trans('app.password-reset-success');
            $response->setData($data);
        } else {
            // If authentication failed (2FA or geo-lock), revert the password change
            DB::rollBack();
        }

        return $response;
    }
}
