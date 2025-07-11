<?php

namespace App\Http\Controllers\API\V4\Admin;

use App\Domain;
use App\Entitlement;
use App\EventLog;
use App\Group;
use App\Payment;
use App\Resource;
use App\SharedFolder;
use App\SharedFolderAlias;
use App\Sku;
use App\User;
use App\UserAlias;
use App\UserSetting;
use App\Wallet;
use App\WalletSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UsersController extends \App\Http\Controllers\API\V4\UsersController
{
    /**
     * Delete a user.
     *
     * @param string $id User identifier
     *
     * @return JsonResponse The response
     */
    public function destroy($id)
    {
        return $this->errorResponse(404);
    }

    /**
     * Searching of user accounts.
     *
     * @return JsonResponse
     */
    public function index()
    {
        $search = trim(request()->input('search'));
        $owner = trim(request()->input('owner'));
        $result = collect([]);

        if ($owner) {
            $owner = User::find($owner);

            if ($owner) {
                $result = $owner->users(false)->orderBy('email')->get();
            }
        } elseif (strpos($search, '@')) {
            // Search by email
            $result = User::withTrashed()->where('email', $search)
                ->orderBy('email')
                ->get();

            if ($result->isEmpty()) {
                // Search by an alias
                $user_ids = UserAlias::where('alias', $search)->get()->pluck('user_id');

                // Search by an external email
                $ext_user_ids = UserSetting::where('key', 'external_email')
                    ->where('value', $search)
                    ->get()
                    ->pluck('user_id');

                $user_ids = $user_ids->merge($ext_user_ids)->unique();

                // Search by an email of a group, resource, shared folder, etc.
                if ($group = Group::withTrashed()->where('email', $search)->first()) {
                    if ($wallet = $group->wallet()) {
                        $user_ids = $user_ids->merge([$wallet->user_id])->unique();
                    }
                } elseif ($resource = Resource::withTrashed()->where('email', $search)->first()) {
                    if ($wallet = $resource->wallet()) {
                        $user_ids = $user_ids->merge([$wallet->user_id])->unique();
                    }
                } elseif ($folder = SharedFolder::withTrashed()->where('email', $search)->first()) {
                    if ($wallet = $folder->wallet()) {
                        $user_ids = $user_ids->merge([$wallet->user_id])->unique();
                    }
                } elseif ($alias = SharedFolderAlias::where('alias', $search)->first()) {
                    if ($wallet = $alias->sharedFolder->wallet()) {
                        $user_ids = $user_ids->merge([$wallet->user_id])->unique();
                    }
                }

                if (!$user_ids->isEmpty()) {
                    $result = User::withTrashed()->whereIn('id', $user_ids)
                        ->orderBy('email')
                        ->get();
                }
            }
        } elseif (is_numeric($search)) {
            // Search by user ID
            $user = User::withTrashed()->where('id', $search)
                ->first();

            if ($user) {
                $result->push($user);
            }
        } elseif (str_contains($search, '.')) {
            // Search by domain
            $domain = Domain::withTrashed()->where('namespace', $search)
                ->first();

            if ($domain) {
                if (($wallet = $domain->wallet()) && ($owner = $wallet->owner()->withTrashed()->first())) {
                    $result->push($owner);
                }
            }
        // A mollie customer ID
        } elseif (substr($search, 0, 4) == 'cst_') {
            $setting = WalletSetting::where(
                [
                    'key' => 'mollie_id',
                    'value' => $search,
                ]
            )->first();

            if ($setting) {
                if ($wallet = $setting->wallet) {
                    if ($owner = $wallet->owner()->withTrashed()->first()) {
                        $result->push($owner);
                    }
                }
            }
        // A mollie transaction ID
        } elseif (substr($search, 0, 3) == 'tr_') {
            $payment = Payment::find($search);

            if ($payment) {
                if ($owner = $payment->wallet->owner()->withTrashed()->first()) {
                    $result->push($owner);
                }
            }
        } elseif (!empty($search)) {
            $wallet = Wallet::find($search);

            if ($wallet) {
                if ($owner = $wallet->owner()->withTrashed()->first()) {
                    $result->push($owner);
                }
            }
        }

        // Process the result
        $result = $result->map(
            function ($user) {
                return $this->objectToClient($user, true);
            }
        );

        $result = [
            'list' => $result,
            'count' => count($result),
            'message' => self::trans('app.search-foundxusers', ['x' => count($result)]),
        ];

        return response()->json($result);
    }

    /**
     * Reset 2-Factor Authentication for the user
     *
     * @param Request $request the API request
     * @param string  $id      User identifier
     *
     * @return JsonResponse The response
     */
    public function reset2FA(Request $request, $id)
    {
        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canUpdate($user)) {
            return $this->errorResponse(403);
        }

        $sku = Sku::withObjectTenantContext($user)->where('title', '2fa')->first();

        // Note: we do select first, so the observer can delete
        //       2FA preferences from Roundcube database, so don't
        //       be tempted to replace first() with delete() below
        $entitlement = $user->entitlements()->where('sku_id', $sku->id)->first();
        $entitlement->delete();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.user-reset-2fa-success'),
        ]);
    }

    /**
     * Reset Geo-Lockin for the user
     *
     * @param Request $request the API request
     * @param string  $id      User identifier
     *
     * @return JsonResponse The response
     */
    public function resetGeoLock(Request $request, $id)
    {
        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canUpdate($user)) {
            return $this->errorResponse(403);
        }

        $user->setConfig(['limit_geo' => []]);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.user-reset-geo-lock-success'),
        ]);
    }

    /**
     * Resync the user
     *
     * @param Request $request the API request
     * @param string  $id      User identifier
     *
     * @return JsonResponse The response
     */
    public function resync(Request $request, $id)
    {
        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canUpdate($user)) {
            return $this->errorResponse(403);
        }

        if (\Artisan::call('user:resync', ['user' => $user->id])) {
            return $this->errorResponse(500);
        }

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.user-resync-success'),
        ]);
    }

    /**
     * Set/Add a SKU for the user
     *
     * @param Request $request the API request
     * @param string  $id      User identifier
     * @param string  $sku     SKU title
     *
     * @return JsonResponse The response
     */
    public function setSku(Request $request, $id, $sku)
    {
        // For now we allow adding the 'beta' SKU only
        if ($sku != 'beta') {
            return $this->errorResponse(404);
        }

        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canUpdate($user)) {
            return $this->errorResponse(403);
        }

        $sku = Sku::withObjectTenantContext($user)->where('title', $sku)->first();

        if (!$sku) {
            return $this->errorResponse(404);
        }

        if ($user->entitlements()->where('sku_id', $sku->id)->first()) {
            return $this->errorResponse(422, self::trans('app.user-set-sku-already-exists'));
        }

        $user->assignSku($sku);

        /** @var Entitlement $entitlement */
        $entitlement = $user->entitlements()->where('sku_id', $sku->id)->first();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.user-set-sku-success'),
            'sku' => [
                'cost' => $entitlement->cost,
                'name' => $sku->name,
                'id' => $sku->id,
            ],
        ]);
    }

    /**
     * Create a new user record.
     *
     * @param Request $request the API request
     *
     * @return JsonResponse The response
     */
    public function store(Request $request)
    {
        return $this->errorResponse(404);
    }

    /**
     * Suspend the user
     *
     * @param Request $request the API request
     * @param string  $id      User identifier
     *
     * @return JsonResponse The response
     */
    public function suspend(Request $request, $id)
    {
        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canUpdate($user)) {
            return $this->errorResponse(403);
        }

        $v = Validator::make($request->all(), ['comment' => 'nullable|string|max:1024']);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $user->suspend();

        EventLog::createFor($user, EventLog::TYPE_SUSPENDED, $request->comment);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.user-suspend-success'),
        ]);
    }

    /**
     * Un-Suspend the user
     *
     * @param Request $request the API request
     * @param string  $id      User identifier
     *
     * @return JsonResponse The response
     */
    public function unsuspend(Request $request, $id)
    {
        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canUpdate($user)) {
            return $this->errorResponse(403);
        }

        $v = Validator::make($request->all(), ['comment' => 'nullable|string|max:1024']);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $user->unsuspend();

        EventLog::createFor($user, EventLog::TYPE_UNSUSPENDED, $request->comment);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.user-unsuspend-success'),
        ]);
    }

    /**
     * Update user data.
     *
     * @param Request $request the API request
     * @param string  $id      User identifier
     *
     * @return JsonResponse The response
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canUpdate($user)) {
            return $this->errorResponse(403);
        }

        // For now admins can change only user external email address

        $rules = [];

        if (array_key_exists('external_email', $request->input())) {
            $rules['external_email'] = 'email';
        }

        // Validate input
        $v = Validator::make($request->all(), $rules);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        // Update user settings
        $settings = $request->only(array_keys($rules));

        if (!empty($settings)) {
            $user->setSettings($settings);
        }

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.user-update-success'),
        ]);
    }

    /**
     * Inspect what kolab4 sees from the request.
     *
     * Useful for testing proxy settings and making sure the X-Forwarded-For Header is picked up.
     *
     * @return JsonResponse The response
     */
    public function inspectRequest(Request $request)
    {
        return response()->json([
            'ip' => $request->ip(),
            'clientIps' => $request->getClientIps(),
            'isFromTrustedProxy' => $request->isFromTrustedProxy(),
            'headers' => $request->headers->all(),
        ]);
    }
}
