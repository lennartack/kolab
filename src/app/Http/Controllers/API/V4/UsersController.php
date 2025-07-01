<?php

namespace App\Http\Controllers\API\V4;

use App\Auth\OAuth;
use App\Domain;
use App\Entitlement;
use App\Group;
use App\Http\Controllers\API\V4\User\DelegationTrait;
use App\Http\Controllers\RelationController;
use App\Jobs\User\CreateJob;
use App\License;
use App\Package;
use App\Plan;
use App\Providers\PaymentProvider;
use App\Resource;
use App\Rules\Password;
use App\Rules\UserEmailLocal;
use App\SharedFolder;
use App\Sku;
use App\User;
use App\VerificationCode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use League\OAuth2\Server\AuthorizationServer;
use Psr\Http\Message\ServerRequestInterface;

class UsersController extends RelationController
{
    use DelegationTrait;

    /** @const array List of user setting keys available for modification in UI */
    public const USER_SETTINGS = [
        'billing_address',
        'country',
        'currency',
        'external_email',
        'first_name',
        'last_name',
        'organization',
        'phone',
    ];

    /**
     * On user create it is filled with a user or group object to force-delete
     * before the creation of a new user record is possible.
     *
     * @var User|Group|null
     */
    protected $deleteBeforeCreate;

    /** @var string Resource localization label */
    protected $label = 'user';

    /** @var string Resource model name */
    protected $model = User::class;

    /** @var array Common object properties in the API response */
    protected $objectProps = ['email'];

    /** @var ?VerificationCode Password reset code to activate on user create/update */
    protected $passCode;

    /**
     * Listing of users.
     *
     * The user-entitlements billed to the current user wallet(s)
     *
     * @return JsonResponse
     */
    public function index()
    {
        $user = $this->guard()->user();
        $search = trim(request()->input('search'));
        $page = (int) (request()->input('page')) ?: 1;
        $pageSize = 20;
        $hasMore = false;

        $result = $user->users();

        // Search by user email, alias or name
        if ($search !== '') {
            // thanks to cloning we skip some extra queries in $user->users()
            $allUsers1 = clone $result;
            $allUsers2 = clone $result;

            $result->whereLike('email', "%{$search}%")
                ->union(
                    $allUsers1->join('user_aliases', 'users.id', '=', 'user_aliases.user_id')
                        ->whereLike('alias', "%{$search}%")
                )
                ->union(
                    $allUsers2->join('user_settings', 'users.id', '=', 'user_settings.user_id')
                        ->whereLike('value', "%{$search}%")
                        ->whereIn('key', ['first_name', 'last_name'])
                );
        }

        $result = $result->orderBy('email')
            ->limit($pageSize + 1)
            ->offset($pageSize * ($page - 1))
            ->get();

        if (count($result) > $pageSize) {
            $result->pop();
            $hasMore = true;
        }

        // Process the result
        $result = $result->map(
            function ($user) {
                return $this->objectToClient($user);
            }
        );

        $result = [
            'list' => $result,
            'count' => count($result),
            'hasMore' => $hasMore,
        ];

        return response()->json($result);
    }

    /**
     * Get a license information.
     *
     * @param string $id   The account to get licenses for
     * @param string $type License type
     *
     * @return JsonResponse The response
     */
    public function licenses(string $id, string $type)
    {
        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canRead($user)) {
            return $this->errorResponse(403);
        }

        $licenses = $user->licenses()->where('type', $type)->orderBy('created_at')->get();

        // No licenses for the user, take one if available
        if (!count($licenses)) {
            DB::beginTransaction();

            $license = License::withObjectTenantContext($user)
                ->where('type', $type)
                ->whereNull('user_id')
                ->limit(1)
                ->lockForUpdate()
                ->first();

            if ($license) {
                $license->user_id = $user->id;
                $license->save();

                $licenses = \collect([$license]);
            }

            DB::commit();
        }

        // Slim down the result set
        $licenses = $licenses->map(static function ($license) {
            return [
                'key' => $license->key,
                'type' => $license->type,
            ];
        });

        return response()->json([
            'list' => $licenses,
            'count' => count($licenses),
            'hasMore' => false, // TODO
        ]);
    }

    /**
     * Webmail Login-As session initialization (via SSO)
     *
     * @param string                 $id         The account to log into
     * @param ServerRequestInterface $psrRequest PSR request
     * @param Request                $request    The API request
     * @param AuthorizationServer    $server     Authorization server
     *
     * @return JsonResponse
     */
    public function loginAs($id, ServerRequestInterface $psrRequest, Request $request, AuthorizationServer $server)
    {
        if (!\config('app.with_loginas')) {
            return $this->errorResponse(404);
        }

        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canDelete($user)) {
            return $this->errorResponse(403);
        }

        if (!$user->hasSku('mailbox')) {
            return $this->errorResponse(403);
        }

        return OAuth::loginAs($user, $psrRequest, $request, $server);
    }

    /**
     * Display information on the user account specified by $id.
     *
     * @param string $id the account to show information for
     *
     * @return JsonResponse
     */
    public function show($id)
    {
        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canRead($user)) {
            return $this->errorResponse(403);
        }

        $response = $this->userResponse($user);

        $response['skus'] = Entitlement::objectEntitlementsSummary($user);
        $response['config'] = $user->getConfig(true);
        $response['aliases'] = $user->aliases()->pluck('alias')->all();

        $code = $user->verificationcodes()->where('active', true)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($code) {
            $response['passwordLinkCode'] = $code->short_code . '-' . $code->code;
        }

        return response()->json($response);
    }

    /**
     * User status (extended) information
     *
     * @param User $user User object
     *
     * @return array Status information
     */
    public static function statusInfo($user): array
    {
        $process = self::processStateInfo(
            $user,
            [
                'user-new' => true,
                'user-ldap-ready' => $user->isLdapReady(),
                'user-imap-ready' => $user->isImapReady(),
            ]
        );

        $wallet = $user->wallet();
        $isController = $wallet->isController($user);
        $isDegraded = $user->isDegraded();

        $plan = $isController ? $wallet->plan() : null;

        $allSkus = Sku::withObjectTenantContext($user)->pluck('title')->all();
        $skus = $user->skuTitles();

        $hasBeta = in_array('beta', $skus) || !in_array('beta', $allSkus);
        $hasMeet = !$isDegraded && \config('app.with_meet') && in_array('room', $allSkus);
        $hasCustomDomain = $wallet->entitlements()->where('entitleable_type', Domain::class)->count() > 0
            // Enable all features if there are no skus for domain-hosting
            || !in_array('domain-hosting', $allSkus);

        $result = [
            'skus' => $skus,
            'enableBeta' => $hasBeta,
            'enableDelegation' => \config('app.with_delegation'),
            'enableDomains' => $isController && ($hasCustomDomain || $plan?->hasDomain()),
            'enableDistlists' => $isController && $hasCustomDomain && \config('app.with_distlists'),
            'enableFiles' => !$isDegraded && $hasBeta && \config('app.with_files'),
            'enableFolders' => $isController && $hasCustomDomain && \config('app.with_shared_folders'),
            'enableMailfilter' => $isController && config('app.with_mailfilter'),
            'enableResources' => $isController && $hasCustomDomain && $hasBeta && \config('app.with_resources'),
            'enableRooms' => $hasMeet,
            'enableSettings' => $isController,
            'enableSubscriptions' => $isController && \config('app.with_subscriptions'),
            'enableUsers' => $isController,
            'enableWallets' => $isController && \config('app.with_wallet'),
            'enableWalletMandates' => $isController,
            'enableWalletPayments' => $isController && $plan?->mode != Plan::MODE_MANDATE,
            'enableCompanionapps' => $hasBeta && \config('app.with_companion_app'),
            'enableLoginAs' => $isController && \config('app.with_loginas'),
        ];

        return array_merge($process, $result);
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
        $current_user = $this->guard()->user();
        $owner = $current_user->walletOwner();

        if ($owner->id != $current_user->id) {
            return $this->errorResponse(403);
        }

        $this->deleteBeforeCreate = null;

        if ($error_response = $this->validateUserRequest($request, null, $settings)) {
            return $error_response;
        }

        if (
            empty($request->package)
            || !($package = Package::withObjectTenantContext($owner)->find($request->package))
        ) {
            $errors = ['package' => self::trans('validation.packagerequired')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        if ($package->isDomain()) {
            $errors = ['package' => self::trans('validation.packageinvalid')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        DB::beginTransaction();

        // @phpstan-ignore-next-line
        if ($this->deleteBeforeCreate) {
            $this->deleteBeforeCreate->forceDelete();
        }

        // Create user record
        $user = User::create([
            'email' => $request->email,
            'password' => $request->password,
            'status' => $owner->isRestricted() ? User::STATUS_RESTRICTED : 0,
        ]);

        $this->activatePassCode($user);

        $owner->assignPackage($package, $user);

        if (!empty($settings)) {
            $user->setSettings($settings);
        }

        if (!empty($request->aliases)) {
            $user->setAliases($request->aliases);
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.user-create-success'),
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

        $current_user = $this->guard()->user();
        $requires_controller = $request->skus !== null || $request->aliases !== null;
        $can_update = $requires_controller ? $current_user->canDelete($user) : $current_user->canUpdate($user);

        // Only wallet controller can set subscriptions and aliases
        // TODO: Consider changes in canUpdate() or introduce isController()
        if (!$can_update) {
            return $this->errorResponse(403);
        }

        if ($error_response = $this->validateUserRequest($request, $user, $settings)) {
            return $error_response;
        }

        DB::beginTransaction();

        SkusController::updateEntitlements($user, $request->skus);

        if (!empty($settings)) {
            $user->setSettings($settings);
        }

        if (!empty($request->password)) {
            $user->password = $request->password;
            $user->save();
        }

        $this->activatePassCode($user);

        if (isset($request->aliases)) {
            $user->setAliases($request->aliases);
        }

        DB::commit();

        $response = [
            'status' => 'success',
            'message' => self::trans('app.user-update-success'),
        ];

        // For self-update refresh the statusInfo in the UI
        if ($user->id == $current_user->id) {
            $response['statusInfo'] = self::statusInfo($user);
        }

        return response()->json($response);
    }

    /**
     * Create a response data array for specified user.
     *
     * @param User $user User object
     *
     * @return array Response data
     */
    public static function userResponse(User $user): array
    {
        $response = array_merge($user->toArray(), self::objectState($user));

        $wallet = $user->wallet();

        // IsLocked flag to lock the user to the Wallet page only
        $response['isLocked'] = (!$user->isActive() && ($plan = $wallet->plan()) && $plan->mode == Plan::MODE_MANDATE);

        // Settings
        $keys = array_merge(self::USER_SETTINGS, ['password_expired']);
        $response['settings'] = $user->settings()->whereIn('key', $keys)->pluck('value', 'key')->all();

        // Status info
        $response['statusInfo'] = self::statusInfo($user);

        // Add more info to the wallet object output
        $map_func = static function ($wallet) use ($user) {
            $result = $wallet->toArray();

            if ($wallet->discount) {
                $result['discount'] = $wallet->discount->discount;
                $result['discount_description'] = $wallet->discount->description;
            }

            if ($wallet->user_id != $user->id) {
                $result['user_email'] = $wallet->owner->email;
            }

            $provider = PaymentProvider::factory($wallet);
            $result['provider'] = $provider->name();

            return $result;
        };

        // Information about wallets and accounts for access checks
        $response['wallets'] = $user->wallets->map($map_func)->toArray();
        $response['accounts'] = $user->accounts->map($map_func)->toArray();
        $response['wallet'] = $map_func($wallet);

        return $response;
    }

    /**
     * Prepare user statuses for the UI
     *
     * @param User $user User object
     *
     * @return array Statuses array
     */
    protected static function objectState($user): array
    {
        $state = parent::objectState($user);

        $state['isAccountDegraded'] = $user->isDegraded(true);

        return $state;
    }

    /**
     * Validate user input
     *
     * @param Request   $request  the API request
     * @param User|null $user     User identifier
     * @param array     $settings User settings (from the request)
     *
     * @return JsonResponse|null The error response on error
     */
    protected function validateUserRequest(Request $request, $user, &$settings = [])
    {
        $rules = [
            'external_email' => 'nullable|email',
            'phone' => 'string|nullable|max:64|regex:/^[0-9+() -]+$/',
            'first_name' => 'string|nullable|max:128',
            'last_name' => 'string|nullable|max:128',
            'organization' => 'string|nullable|max:512',
            'billing_address' => 'string|nullable|max:1024',
            'country' => 'string|nullable|alpha|size:2',
            'currency' => 'string|nullable|alpha|size:3',
            'aliases' => 'array|nullable',
        ];

        $controller = ($user ?: $this->guard()->user())->walletOwner();

        // Handle generated password reset code
        if ($code = $request->input('passwordLinkCode')) {
            // Accept <short-code>-<code> input
            if (strpos($code, '-')) {
                $code = explode('-', $code)[1];
            }

            $this->passCode = $this->guard()->user()->verificationcodes()
                ->where('code', $code)->where('active', false)->first();

            // Generate a password for a new user with password reset link
            // FIXME: Should/can we have a user with no password set?
            if ($this->passCode && empty($user)) {
                $request->password = $request->password_confirmation = Str::random(16);
                $ignorePassword = true;
            }
        }

        if (empty($user) || !empty($request->password) || !empty($request->password_confirmation)) {
            if (empty($ignorePassword)) {
                $rules['password'] = ['required', 'confirmed', new Password($controller)];
            }
        }

        $errors = [];

        // Validate input
        $v = Validator::make($request->all(), $rules);

        if ($v->fails()) {
            $errors = $v->errors()->toArray();
        }

        // For new user validate email address
        if (empty($user)) {
            $email = $request->email;

            if (empty($email)) {
                $errors['email'] = self::trans('validation.required', ['attribute' => 'email']);
            } elseif ($error = self::validateEmail($email, $controller, $this->deleteBeforeCreate)) {
                $errors['email'] = $error;
            }
        }

        // Validate aliases input
        if (isset($request->aliases)) {
            $aliases = [];
            $existing_aliases = $user ? $user->aliases()->get()->pluck('alias')->toArray() : [];

            foreach ($request->aliases as $idx => $alias) {
                if (is_string($alias) && !empty($alias)) {
                    // Alias cannot be the same as the email address (new user)
                    if (!empty($email) && Str::lower($alias) == Str::lower($email)) {
                        continue;
                    }

                    // validate new aliases
                    if (
                        !in_array($alias, $existing_aliases)
                        && ($error = self::validateAlias($alias, $controller))
                    ) {
                        if (!isset($errors['aliases'])) {
                            $errors['aliases'] = [];
                        }
                        $errors['aliases'][$idx] = $error;
                        continue;
                    }

                    $aliases[] = $alias;
                }
            }

            $request->aliases = $aliases;
        }

        if (!empty($errors)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // Update user settings
        $settings = $request->only(array_keys($rules));
        unset($settings['password'], $settings['aliases'], $settings['email']);

        return null;
    }

    /**
     * Execute (synchronously) specified step in a user setup process.
     *
     * @param User   $user User object
     * @param string $step Step identifier (as in self::statusInfo())
     *
     * @return bool|null True if the execution succeeded, False if not, Null when
     *                   the job has been sent to the worker (result unknown)
     */
    public static function execProcessStep(User $user, string $step): ?bool
    {
        try {
            if (str_starts_with($step, 'domain-')) {
                return DomainsController::execProcessStep($user->domain(), $step);
            }

            switch ($step) {
                case 'user-ldap-ready':
                case 'user-imap-ready':
                    // Use worker to do the job, frontend might not have the IMAP admin credentials
                    CreateJob::dispatch($user->id);
                    return null;
            }
        } catch (\Exception $e) {
            \Log::error($e);
        }

        return false;
    }

    /**
     * Email address validation for use as a user mailbox (login).
     *
     * @param string $email   Email address
     * @param User   $user    The account owner
     * @param mixed  $deleted Filled with an instance of a deleted model object
     *                        with the specified email address, if exists
     *
     * @return ?string Error message on validation error
     */
    public static function validateEmail(string $email, User $user, &$deleted = null): ?string
    {
        $deleted = null;

        if (!str_contains($email, '@')) {
            return self::trans('validation.entryinvalid', ['attribute' => 'email']);
        }

        [$login, $domain] = explode('@', Str::lower($email));

        if ($login === '' || $domain === '') {
            return self::trans('validation.entryinvalid', ['attribute' => 'email']);
        }

        // Check if domain exists
        $domain = Domain::withObjectTenantContext($user)->where('namespace', $domain)->first();

        if (empty($domain)) {
            return self::trans('validation.domaininvalid');
        }

        // Validate login part alone
        $v = Validator::make(
            ['email' => $login],
            ['email' => ['required', new UserEmailLocal(!$domain->isPublic())]]
        );

        if ($v->fails()) {
            return $v->errors()->toArray()['email'][0];
        }

        // Check if it is one of domains available to the user
        if (!$domain->isPublic() && $user->id != $domain->walletOwner()->id) {
            return self::trans('validation.entryexists', ['attribute' => 'domain']);
        }

        // Check if the address is already taken
        if ($existing = self::findEmail($email)) {
            // If this is a deleted user/group/resource/folder in the same custom domain
            // we'll force delete it before creating the target user
            if (is_object($existing) && !$domain->isPublic() && $existing->trashed()) {
                $deleted = $existing;
            } else {
                return self::trans('validation.entryexists', ['attribute' => 'email']);
            }
        }

        return null;
    }

    /**
     * Email address validation for use as an alias.
     *
     * @param string $email Email address
     * @param User   $user  The account owner
     *
     * @return ?string Error message on validation error
     */
    public static function validateAlias(string $email, User $user): ?string
    {
        if (!str_contains($email, '@')) {
            return self::trans('validation.entryinvalid', ['attribute' => 'alias']);
        }

        [$login, $domain] = explode('@', Str::lower($email));

        if ($login === '' || $domain === '') {
            return self::trans('validation.entryinvalid', ['attribute' => 'alias']);
        }

        // Check if domain exists
        $domain = Domain::withObjectTenantContext($user)->where('namespace', $domain)->first();

        if (empty($domain)) {
            return self::trans('validation.domaininvalid');
        }

        // Validate login part alone
        $v = Validator::make(
            ['alias' => $login],
            ['alias' => ['required', new UserEmailLocal(!$domain->isPublic())]]
        );

        if ($v->fails()) {
            return $v->errors()->toArray()['alias'][0];
        }

        // Check if it is one of domains available to the user
        if (!$domain->isPublic() && $user->id != $domain->walletOwner()->id) {
            return self::trans('validation.entryexists', ['attribute' => 'domain']);
        }

        // Check if a user with specified address already exists
        if ($existing_user = User::emailExists($email, true)) {
            // Allow an alias in a custom domain to an address that was a user before
            if ($domain->isPublic() || !$existing_user->trashed()) {
                return self::trans('validation.entryexists', ['attribute' => 'alias']);
            }
        }

        // Check if a group/resource/shared folder with specified address already exists
        if (
            Group::emailExists($email)
            || Resource::emailExists($email)
            || SharedFolder::emailExists($email)
        ) {
            return self::trans('validation.entryexists', ['attribute' => 'alias']);
        }

        // Check if an alias with specified address already exists
        if (User::aliasExists($email) || SharedFolder::aliasExists($email)) {
            // Allow assigning the same alias to a user in the same group account,
            // but only for non-public domains
            if ($domain->isPublic()) {
                return self::trans('validation.entryexists', ['attribute' => 'alias']);
            }
        }

        return null;
    }

    /**
     * Activate password reset code (if set), and assign it to a user.
     *
     * @param User $user The user
     */
    protected function activatePassCode(User $user): void
    {
        // Activate the password reset code
        if ($this->passCode) {
            $this->passCode->user_id = $user->id;
            $this->passCode->active = true;
            $this->passCode->save();
        }
    }
}
