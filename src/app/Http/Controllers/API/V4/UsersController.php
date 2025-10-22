<?php

namespace App\Http\Controllers\API\V4;

use App\Auth\OAuth;
use App\Domain;
use App\Group;
use App\Http\Controllers\API\V4\User\DelegationTrait;
use App\Http\Controllers\RelationController;
use App\Http\Resources\UserInfoExtendedResource;
use App\Http\Resources\UserResource;
use App\Jobs\Mail\EmailVerificationJob;
use App\Jobs\User\CreateJob;
use App\Package;
use App\Resource;
use App\Rules\Password;
use App\Rules\UserEmailLocal;
use App\SharedFolder;
use App\Sku;
use App\User;
use App\VerificationCode;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
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
     * Verification code validation
     *
     * @param Request $request the API request
     * @param string  $id      User identifier
     * @param string  $code    Verification code identifier
     */
    public function codeValidation(Request $request, $id, $code): JsonResponse
    {
        // Validate the request args
        $v = Validator::make(
            $request->all(),
            [
                // Verification code secret
                'short_code' => 'required',
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        // Validate the verification code
        $code = VerificationCode::where('code', $code)->where('active', true)->first();

        if ($code && ($this->guard()->user()->id != $code->user_id || $code->user_id != $id)) {
            return $this->errorResponse(403);
        }

        if (empty($code) || !$code->codeValidate($request->short_code, $message)) {
            $errors = ['short_code' => self::trans('validation.verificationcodeinvalid')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            // Verification code mode
            'mode' => $code->mode,
        ]);
    }

    /**
     * Listing of users.
     *
     * The list includes users billed to the current user wallet(s). It returns
     * one page at a time. The page size is 20.
     */
    #[QueryParameter('search', description: 'Search string', type: 'string')]
    #[QueryParameter('page', description: 'Page number', type: 'int', default: 1)]
    public function index(): JsonResponse
    {
        $user = $this->guard()->user();
        $search = trim(request()->input('search'));
        $page = (int) (request()->input('page')) ?: 1;
        $pageSize = 20;
        $hasMore = false;

        $result = $user->users();

        // Search by role
        if (str_starts_with($search, 'role:')) {
            // Finding out account controllers is tricky. Which wallet(s)?
            $wallets = array_filter($result->getBindings(), fn ($v) => !str_contains($v, '\\'));

            $controllers = User::whereIn('id', DB::table('user_accounts')->select('user_id')->whereIn('wallet_id', $wallets));

            if ($search == 'role:controller') {
                $result = $controllers;
            } else {
                // role:user
                $result = $result->whereNotIn('users.id', $controllers->pluck('id')->all());
            }
        }
        // Search by user email, alias or name
        elseif ($search !== '') {
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

        $result = [
            // List of users
            'list' => UserResource::collection($result),
            // @var int Number of entries in the list
            'count' => count($result),
            // @var bool Indicates that there are more entries available
            'hasMore' => $hasMore,
        ];

        return response()->json($result);
    }

    /**
     * Webmail Login-As session initialization (via SSO)
     *
     * @param string                 $id         The account to log into
     * @param ServerRequestInterface $psrRequest PSR request
     * @param Request                $request    The API request
     * @param AuthorizationServer    $server     Authorization server
     */
    public function loginAs($id, ServerRequestInterface $psrRequest, Request $request, AuthorizationServer $server): JsonResponse
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
     * User information.
     *
     * @param string $id The user identifier
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

        return new UserInfoExtendedResource($user);
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
        $isOwner = $wallet->user_id == $user->id;
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
            'enableSettings' => $isOwner,
            'enableSubscriptions' => $isController && \config('app.with_subscriptions'),
            'enableUsers' => $isController,
            'enableWallets' => $isOwner && \config('app.with_wallet'),
            'enableWalletMandates' => $isOwner,
            'enableCompanionapps' => $hasBeta && \config('app.with_companion_app'),
            'enableLoginAs' => $isController && \config('app.with_loginas'),
        ];

        return array_merge($process, $result);
    }

    /**
     * Create a new user.
     */
    #[BodyParameter('email', description: 'Email address', type: 'string', required: true)]
    #[BodyParameter('package', description: 'SKU package identifier', type: 'string', required: true)]
    #[BodyParameter('external_email', description: 'External email address', type: 'string')]
    #[BodyParameter('phone', description: 'Phone number', type: 'string')]
    #[BodyParameter('first_name', description: 'First name', type: 'string')]
    #[BodyParameter('last_name', description: 'Last name', type: 'string')]
    #[BodyParameter('organization', description: 'Organization name', type: 'string')]
    #[BodyParameter('billing_address', description: 'Billing address', type: 'string')]
    #[BodyParameter('country', description: 'Country code', type: 'string')]
    #[BodyParameter('currency', description: 'Currency code', type: 'string')]
    #[BodyParameter('password', description: 'New password', type: 'string')]
    #[BodyParameter('password_confirmation', description: 'New password confirmation', type: 'string')]
    #[BodyParameter('passwordLinkCode', description: 'Code for a by-link password reset', type: 'string')]
    #[BodyParameter('aliases', description: 'Email address aliases', type: 'array<string>')]
    public function store(Request $request): JsonResponse
    {
        $current_user = $this->guard()->user();
        $wallet = $current_user->wallet();

        if (!$wallet || !$wallet->isController($current_user) || !$wallet->owner) {
            return $this->errorResponse(403);
        }

        $this->deleteBeforeCreate = null;

        if ($errors = $this->validateUserRequest($request, null, $settings)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        if (
            empty($request->package)
            || !($package = Package::withObjectTenantContext($current_user)->find($request->package))
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
            'status' => $wallet->owner->isRestricted() ? User::STATUS_RESTRICTED : 0,
        ]);

        $this->activatePassCode($user);

        $wallet->owner->assignPackage($package, $user);

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
     */
    #[BodyParameter('external_email', description: 'External email address', type: 'string')]
    #[BodyParameter('phone', description: 'Phone number', type: 'string')]
    #[BodyParameter('first_name', description: 'First name', type: 'string')]
    #[BodyParameter('last_name', description: 'Last name', type: 'string')]
    #[BodyParameter('organization', description: 'Organization name', type: 'string')]
    #[BodyParameter('billing_address', description: 'Billing address', type: 'string')]
    #[BodyParameter('country', description: 'Country code', type: 'string')]
    #[BodyParameter('currency', description: 'Currency code', type: 'string')]
    #[BodyParameter('password', description: 'New password', type: 'string')]
    #[BodyParameter('password_confirmation', description: 'New password confirmation', type: 'string')]
    #[BodyParameter('passwordLinkCode', description: 'Code for a by-link password reset', type: 'string')]
    #[BodyParameter('skus', description: 'Enabled SKUs', type: 'array')]
    #[BodyParameter('aliases', description: 'Email address aliases', type: 'array<string>')]
    public function update(Request $request, $id): JsonResponse
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

        if ($errors = $this->validateUserRequest($request, $user, $settings)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        $response = [
            'status' => 'success',
            'message' => self::trans('app.user-update-success'),
            // @var array|null Extended status/permissions information
            'statusInfo' => null,
            // @var array Extra settings that got added in this action
            'settings' => [],
        ];

        DB::beginTransaction();

        SkusController::updateEntitlements($user, $request->skus);

        if (!empty($settings)) {
            if ($user->id == $current_user->id && array_key_exists('external_email', $settings)) {
                if (!empty($settings['external_email'])) {
                    // User changes his own external email, required code verification
                    if ($settings['external_email'] != $user->getSetting('external_email')) {
                        $code = $user->verificationCodes()->create(['mode' => VerificationCode::MODE_EMAIL]);
                        $extras = [
                            'external_email_new' => $settings['external_email'],
                            'external_email_code' => $code->code,
                        ];
                        $response['settings'] = array_merge($response['settings'], $extras);
                        $settings = array_merge($settings, $extras);
                        unset($settings['external_email']);
                        EmailVerificationJob::dispatch($code->code)->afterCommit();
                    }
                } else {
                    // User removes his own external email
                    $extras = [
                        'external_email_new' => null,
                        'external_email_code' => null,
                    ];
                    $response['settings'] = array_merge($response['settings'], $extras);
                    $settings = array_merge($settings, $extras);
                }
            }

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

        // For self-update refresh the statusInfo in the UI
        if ($user->id == $current_user->id) {
            $response['statusInfo'] = self::statusInfo($user);
        }

        return response()->json($response);
    }

    /**
     * Validate user input
     *
     * @param Request   $request  the API request
     * @param User|null $user     User identifier
     * @param array     $settings User settings (from the request)
     *
     * @return array|null The error response on error
     */
    protected function validateUserRequest(Request $request, $user, &$settings = []): ?array
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

            $this->passCode = $this->guard()->user()->verificationCodes()
                ->where('code', $code)
                ->where('mode', VerificationCode::MODE_PASSWORD)
                ->where('active', false)
                ->first();

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
            return $errors;
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
        if (!$domain->isPublic() && $user->id != $domain->walletOwner()?->id) {
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
