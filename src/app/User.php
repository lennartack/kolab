<?php

namespace App;

use App\Auth\SecondFactor;
use App\Auth\Utils as AuthUtils;
use App\Policy\Password;
use App\Traits\AliasesTrait;
use App\Traits\BelongsToTenantTrait;
use App\Traits\EmailPropertyTrait;
use App\Traits\EntitleableTrait;
use App\Traits\SettingsTrait;
use App\Traits\StatusPropertyTrait;
use App\Traits\UserConfigTrait;
use App\Traits\UuidIntKeyTrait;
use Dyrynda\Database\Support\NullableFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\HasApiTokens;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * The eloquent definition of a User.
 *
 * @property string  $email         User email address
 * @property int     $id            User identifier
 * @property string  $password      User password
 * @property string  $password_ldap User LDAP password
 * @property ?string $role          User role
 * @property int     $status        User status
 * @property int     $tenant_id     Tenant identifier
 */
class User extends Authenticatable
{
    use AliasesTrait;
    use BelongsToTenantTrait;
    use EntitleableTrait;
    use HasApiTokens;
    use Notifiable;
    use NullableFields;
    use SettingsTrait;
    use SoftDeletes;
    use StatusPropertyTrait;
    use UserConfigTrait;
    use UuidIntKeyTrait;
    use EmailPropertyTrait; // must be after UuidIntKeyTrait

    // a new user, default on creation
    public const STATUS_NEW = 1 << 0;
    // it's been activated
    public const STATUS_ACTIVE = 1 << 1;
    // user has been suspended
    public const STATUS_SUSPENDED = 1 << 2;
    // user has been deleted
    public const STATUS_DELETED = 1 << 3;
    // user has been created in LDAP
    public const STATUS_LDAP_READY = 1 << 4;
    // user mailbox has been created in IMAP
    public const STATUS_IMAP_READY = 1 << 5;
    // user in "limited feature-set" state
    public const STATUS_DEGRADED = 1 << 6;
    // a restricted user
    public const STATUS_RESTRICTED = 1 << 7;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_RESELLER = 'reseller';
    public const ROLE_SERVICE = 'service';
    public const ROLE_DEVICE = 'device';

    /** @var int The allowed states for this object used in StatusPropertyTrait */
    private int $allowed_states = self::STATUS_NEW
        | self::STATUS_ACTIVE
        | self::STATUS_SUSPENDED
        | self::STATUS_DELETED
        | self::STATUS_LDAP_READY
        | self::STATUS_IMAP_READY
        | self::STATUS_DEGRADED
        | self::STATUS_RESTRICTED;

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = [
        'id',
        'email',
        'password',
        'password_ldap',
        'status',
    ];

    /** @var list<string> The attributes that should be hidden for arrays */
    protected $hidden = [
        'password',
        'password_ldap',
        'role',
    ];

    /** @var array<int, string> The attributes that can be null */
    protected $nullable = [
        'password',
        'password_ldap',
        'role',
    ];

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * Any wallets on which this user is a controller.
     *
     * This does not include wallets owned by the user.
     *
     * @return BelongsToMany<Wallet, $this>
     */
    public function accounts()
    {
        return $this->belongsToMany(
            Wallet::class,      // The foreign object definition
            'user_accounts',    // The table name
            'user_id',          // The local foreign key
            'wallet_id'         // The remote foreign key
        );
    }

    /**
     * Assign a package to a user. The user should not have any existing entitlements.
     *
     * @param Package   $package the package to assign
     * @param User|null $user    assign the package to another user
     *
     * @return User
     */
    public function assignPackage($package, $user = null)
    {
        if (!$user) {
            $user = $this;
        }

        return $user->assignPackageAndWallet($package, $this->wallets()->first());
    }

    /**
     * Assign a package plan to a user.
     *
     * @param Plan   $plan   The plan to assign
     * @param Domain $domain Optional domain object
     *
     * @return User Self
     *
     * @throws \Exception
     */
    public function assignPlan($plan, $domain = null): self
    {
        $domain_packages = $plan->packages->filter(static function ($package) {
            return $package->isDomain();
        });

        // Before we do anything let's make sure that a custom domain can be assigned only
        // to a plan with a domain package
        if ($domain && $domain_packages->isEmpty()) {
            throw new \Exception("Custom domain requires a plan with a domain SKU");
        }

        foreach ($plan->packages->diff($domain_packages) as $package) {
            $this->assignPackage($package);
        }

        if ($domain) {
            foreach ($domain_packages as $package) {
                $domain->assignPackage($package, $this);
            }
        }

        $this->setSetting('plan_id', $plan->id);

        return $this;
    }

    /**
     * Check if current user can delete another object.
     *
     * @param mixed $object A user|domain|wallet|group object
     *
     * @return bool True if he can, False otherwise
     */
    public function canDelete($object): bool
    {
        if (!is_object($object) || !method_exists($object, 'wallet')) {
            return false;
        }

        $wallet = $object->wallet();
        if (!$wallet) {
            return false;
        }

        // Wallet owner can do everything
        if ($wallet->user_id == $this->id) {
            return true;
        }

        // Other wallet controllers can remove users, but not the account owner nor themselves
        if ($object instanceof self) {
            if ($object->id == $wallet->user_id || $object->id == $this->id) {
                return false;
            }
        }

        return $wallet->isController($this);
    }

    /**
     * Check if current user can read data of another object.
     *
     * @param mixed $object A user|domain|wallet|group object
     *
     * @return bool True if he can, False otherwise
     */
    public function canRead($object): bool
    {
        if ($this->role == self::ROLE_ADMIN) {
            return true;
        }

        if ($object instanceof self && $this->id == $object->id) {
            return true;
        }

        if ($this->role == self::ROLE_RESELLER) {
            if ($object instanceof self && $object->role == self::ROLE_ADMIN) {
                return false;
            }

            if ($object instanceof Wallet && !empty($object->owner)) {
                $object = $object->owner;
            }

            return isset($object->tenant_id) && $object->tenant_id == $this->tenant_id;
        }

        if ($object instanceof Wallet) {
            return $object->user_id == $this->id || $object->controllers->contains($this);
        }

        if (!method_exists($object, 'wallet')) {
            return false;
        }

        $wallet = $object->wallet();

        return $wallet && ($wallet->user_id == $this->id || $this->accounts->contains($wallet));
    }

    /**
     * Check if current user can update data of another object.
     *
     * @param mixed $object A user|domain|wallet|group object
     *
     * @return bool True if he can, False otherwise
     */
    public function canUpdate($object): bool
    {
        if ($object instanceof self && $this->id == $object->id) {
            return true;
        }

        if ($this->role == self::ROLE_ADMIN) {
            return true;
        }

        if ($this->role == self::ROLE_RESELLER) {
            if ($object instanceof self && $object->role == self::ROLE_ADMIN) {
                return false;
            }

            if ($object instanceof Wallet && !empty($object->owner)) {
                $object = $object->owner;
            }

            return isset($object->tenant_id) && $object->tenant_id == $this->tenant_id;
        }

        return $this->canDelete($object);
    }

    /**
     * Check if current user can update config of another object.
     *
     * @param mixed $object A user|domain|wallet|group object
     *
     * @return bool True if he can, False otherwise
     */
    public function canUpdateConfig($object): bool
    {
        if (!is_object($object) || !method_exists($object, 'wallet')) {
            return false;
        }

        $wallet = $object->wallet();
        if (!$wallet) {
            return false;
        }

        // Wallet owner can do everything
        if ($wallet->user_id == $this->id) {
            return true;
        }

        // Other wallet controllers can update config of another user, except the wallet controller
        if ($object instanceof self && $object->id == $wallet->user_id) {
            return false;
        }

        return $wallet->isController($this);
    }

    /**
     * Contacts (global addressbook) for this user.
     *
     * @return HasMany<Contact, $this>
     */
    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Degrade the user
     */
    public function degrade(): void
    {
        if ($this->isDegraded()) {
            return;
        }

        $this->status |= self::STATUS_DEGRADED;
        $this->save();
    }

    /**
     * Users that this user is delegatee of.
     *
     * @return BelongsToMany<User, $this, Delegation>
     */
    public function delegators()
    {
        return $this->belongsToMany(self::class, 'delegations', 'delegatee_id', 'user_id')
            ->as('delegation')
            ->using(Delegation::class);
    }

    /**
     * Users that are delegatees of this user.
     *
     * @return BelongsToMany<User, $this, Delegation>
     */
    public function delegatees()
    {
        return $this->belongsToMany(self::class, 'delegations', 'user_id', 'delegatee_id')
            ->as('delegation')
            ->using(Delegation::class)
            ->withPivot('options');
    }

    /**
     * List the domains to which this user is entitled.
     *
     * @param bool $with_accounts include domains assigned to wallets
     *                            the current user controls but not owns
     * @param bool $with_public   include active public domains (for the user tenant)
     *
     * @return Builder Query builder
     */
    public function domains($with_accounts = true, $with_public = true)
    {
        $domains = $this->entitleables(Domain::class, $with_accounts);

        if ($with_public) {
            $domains->orWhere(function ($query) {
                if (!$this->tenant_id) {
                    $query->where('tenant_id', $this->tenant_id);
                } else {
                    $query->withEnvTenantContext();
                }

                $query->where('domains.type', '&', Domain::TYPE_PUBLIC)
                    ->where('domains.status', '&', Domain::STATUS_ACTIVE);
            });
        }

        return $domains;
    }

    /**
     * Return entitleable objects of a specified type controlled by the current user.
     *
     * @param string $class         Object class
     * @param bool   $with_accounts include objects assigned to wallets
     *                              the current user controls, but not owns
     *
     * @return Builder Query builder
     */
    private function entitleables(string $class, bool $with_accounts = true)
    {
        $wallets = $this->wallets()->pluck('id')->all();

        if ($with_accounts) {
            $wallets = array_merge($wallets, $this->accounts()->pluck('wallet_id')->all());
        }

        $object = new $class();
        $table = $object->getTable();

        return $object->select("{$table}.*")
            ->whereExists(static function ($query) use ($table, $wallets, $class) {
                $query->select(DB::raw(1))
                    ->from('entitlements')
                    ->whereColumn('entitleable_id', "{$table}.id")
                    ->whereIn('entitlements.wallet_id', $wallets)
                    ->where('entitlements.entitleable_type', $class);
            });
    }

    /**
     * Helper to find user by email address, whether it is
     * main email address, alias or an external email.
     *
     * If there's more than one alias NULL will be returned.
     *
     * @param string $email    Email address
     * @param bool   $external Search also for an external email
     *
     * @return User|null User model object if found
     */
    public static function findByEmail(string $email, bool $external = false): ?self
    {
        if (!str_contains($email, '@')) {
            return null;
        }

        $email = \strtolower($email);

        $user = self::where('email', $email)->first();

        if ($user) {
            return $user;
        }

        $aliases = UserAlias::where('alias', $email)->get();

        if (count($aliases) == 1) {
            return $aliases->first()->user;
        }

        // TODO: External email

        return null;
    }

    /**
     * Storage items for this user.
     *
     * @return HasMany<Fs\Item, $this>
     */
    public function fsItems()
    {
        return $this->hasMany(Fs\Item::class);
    }

    /**
     * Return groups controlled by the current user.
     *
     * @param bool $with_accounts include groups assigned to wallets
     *                            the current user controls but not owns
     *
     * @return Builder Query builder
     */
    public function groups($with_accounts = true)
    {
        return $this->entitleables(Group::class, $with_accounts);
    }

    /**
     * Returns whether this user (or its wallet owner) is degraded.
     *
     * @param bool $owner Check also the wallet owner instead just the user himself
     */
    public function isDegraded(bool $owner = false): bool
    {
        if ($this->status & self::STATUS_DEGRADED) {
            return true;
        }

        if ($owner && ($wallet = $this->wallet())) {
            return $wallet->user_id != $this->id && $wallet->owner && $wallet->owner->isDegraded();
        }

        return false;
    }

    /**
     * Check if multi factor authentication is enabled
     */
    public function isMFAEnabled(): bool
    {
        return CompanionApp::where('user_id', $this->id)
            ->where('mfa_enabled', true)
            ->exists();
    }

    /**
     * Returns whether this user is restricted.
     */
    public function isRestricted(): bool
    {
        return ($this->status & self::STATUS_RESTRICTED) > 0;
    }

    /**
     * Check if user has a special role, and it should not have a mailbox
     */
    public function isVirtual(): bool
    {
        if ($this->role == self::ROLE_SERVICE || $this->role == self::ROLE_DEVICE) {
            return true;
        }

        // TODO: this can be removed in favor of the above once we are sure the role is set everywhere.
        if ($this->email && $this->email == \config('services.imap.admin_login')) {
            return true;
        }

        return false;
    }

    /**
     * Licenses whis user has.
     *
     * @return HasMany<License, $this>
     */
    public function licenses()
    {
        return $this->hasMany(License::class);
    }

    /**
     * A shortcut to get the user name.
     *
     * @param bool $fallback Return "<aa.name> User" if there's no name
     *
     * @return string Full user name
     */
    public function name(bool $fallback = false): string
    {
        $settings = $this->getSettings(['first_name', 'last_name']);

        $name = trim($settings['first_name'] . ' ' . $settings['last_name']);

        if (empty($name) && $fallback) {
            return trim(\trans('app.siteuser', ['site' => Tenant::getConfig($this->tenant_id, 'app.name')]));
        }

        return $name;
    }

    /**
     * Old passwords for this user.
     *
     * @return HasMany<UserPassword, $this>
     */
    public function passwords()
    {
        return $this->hasMany(UserPassword::class);
    }

    /**
     * Restrict this user.
     */
    public function restrict(): void
    {
        if ($this->isRestricted()) {
            return;
        }

        $this->status |= self::STATUS_RESTRICTED;
        $this->save();
    }

    /**
     * Return resources controlled by the current user.
     *
     * @param bool $with_accounts include resources assigned to wallets
     *                            the current user controls but not owns
     *
     * @return Builder Query builder
     */
    public function resources($with_accounts = true)
    {
        return $this->entitleables(Resource::class, $with_accounts);
    }

    /**
     * Return rooms controlled by the current user.
     *
     * @param bool $with_accounts include rooms assigned to wallets
     *                            the current user controls but not owns
     *
     * @return Builder Query builder
     */
    public function rooms($with_accounts = true)
    {
        return $this->entitleables(Meet\Room::class, $with_accounts);
    }

    /**
     * Return shared folders controlled by the current user.
     *
     * @param bool $with_accounts include folders assigned to wallets
     *                            the current user controls but not owns
     *
     * @return Builder Query builder
     */
    public function sharedFolders($with_accounts = true)
    {
        return $this->entitleables(SharedFolder::class, $with_accounts);
    }

    /**
     * Return companion apps by the current user.
     *
     * @return Builder Query builder
     */
    public function companionApps()
    {
        return CompanionApp::where('user_id', $this->id);
    }

    public function senderPolicyFrameworkWhitelist($clientName)
    {
        $setting = $this->getSetting('spf_whitelist');

        if (!$setting) {
            return false;
        }

        $whitelist = json_decode($setting);

        $matchFound = false;

        foreach ($whitelist as $entry) {
            if (substr($entry, 0, 1) == '/') {
                $match = preg_match($entry, $clientName);

                if ($match) {
                    $matchFound = true;
                }

                continue;
            }

            if (substr($entry, 0, 1) == '.') {
                if (substr($clientName, -1 * strlen($entry)) == $entry) {
                    $matchFound = true;
                }

                continue;
            }

            if ($entry == $clientName) {
                $matchFound = true;
                continue;
            }
        }

        return $matchFound;
    }

    /**
     * Un-degrade this user.
     */
    public function undegrade(): void
    {
        if (!$this->isDegraded()) {
            return;
        }

        $this->status ^= self::STATUS_DEGRADED;
        $this->save();
    }

    /**
     * Un-restrict this user.
     *
     * @param bool $deep Unrestrict also all users in the account
     */
    public function unrestrict(bool $deep = false): void
    {
        if ($this->isRestricted()) {
            $this->status ^= self::STATUS_RESTRICTED;
            $this->save();
        }

        // Remove the flag from all users in the user's wallets
        if ($deep) {
            $this->wallets->each(static function ($wallet) {
                User::whereIn('id', $wallet->entitlements()->select('entitleable_id')
                    ->where('entitleable_type', User::class))
                    ->each(static function ($user) {
                        $user->unrestrict();
                    });
            });
        }
    }

    /**
     * Return users controlled by the current user.
     *
     * @param bool $with_accounts include users assigned to wallets
     *                            the current user controls but not owns
     *
     * @return Builder Query builder
     */
    public function users($with_accounts = true)
    {
        return $this->entitleables(self::class, $with_accounts);
    }

    /**
     * Verification codes for this user.
     *
     * @return HasMany<VerificationCode, $this>
     */
    public function verificationCodes()
    {
        return $this->hasMany(VerificationCode::class, 'user_id', 'id');
    }

    /**
     * Wallets this user owns.
     *
     * @return HasMany<Wallet, $this>
     */
    public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }

    /**
     * User password mutator
     *
     * @param string $password the password in plain text
     */
    public function setPasswordAttribute($password)
    {
        if (!empty($password)) {
            $this->attributes['password'] = Hash::make($password);
            $this->attributes['password_ldap'] = '{SSHA512}' . base64_encode(
                pack('H*', hash('sha512', $password))
            );
        }
    }

    /**
     * User LDAP password mutator
     *
     * @param string $password the password in plain text
     */
    public function setPasswordLdapAttribute($password)
    {
        $this->setPasswordAttribute($password);
    }

    /**
     * User role mutator
     *
     * @param ?string $role The user role
     */
    public function setRoleAttribute($role)
    {
        if ($role !== null && !in_array($role, [self::ROLE_ADMIN, self::ROLE_RESELLER, self::ROLE_SERVICE, self::ROLE_DEVICE])) {
            throw new \Exception("Invalid role: {$role}");
        }

        $this->attributes['role'] = $role;
    }

    /**
     * Returns list of supported user roles
     */
    public static function supportedRoles(): array
    {
        $class = new \ReflectionClass(__CLASS__);

        return array_values(array_filter(
            $class->getConstants(),
            fn ($key) => str_starts_with($key, 'ROLE_'),
            \ARRAY_FILTER_USE_KEY
        ));
    }

    /**
     * Suspend all users/domains/groups in this account.
     */
    public function suspendAccount(): void
    {
        $this->suspend();

        foreach ($this->wallets as $wallet) {
            $wallet->entitlements()->select('entitleable_id', 'entitleable_type')
                ->distinct()
                ->get()
                ->each(static function ($entitlement) {
                    if (
                        defined($entitlement->entitleable_type . '::STATUS_SUSPENDED')
                        && $entitlement->entitleable
                    ) {
                        $entitlement->entitleable->suspend();
                    }
                });
        }
    }

    /**
     * Validate the user credentials
     *
     * @param string $password      The password in plain text
     * @param bool   $allow_expired Allow expired password
     *
     * @return true|string True on success, Error reason otherwise
     */
    public function validatePassword(string $password, bool $allow_expired = false)
    {
        if (!empty($this->password)) {
            $authenticated = Hash::check($password, $this->password);
        } elseif (!empty($this->password_ldap)) {
            $authenticated = Password::checkHash($password, $this->password_ldap);
        } else {
            \Log::error("Missing password for {$this->email}");
            $authenticated = false;
        }

        // Note: We intentionally check if password is expired when we know it's valid
        if ($authenticated === true && !$allow_expired && $this->role != self::ROLE_SERVICE && $this->getSetting('password_expired')) {
            $authenticated = AuthAttempt::REASON_PASSWORD_EXPIRED;
        }

        if ($authenticated === true) {
            if (empty($this->password) || empty($this->password_ldap)) {
                $this->password = $password;
                $this->save();
            }
        } elseif ($authenticated === false) {
            $authenticated = AuthAttempt::REASON_PASSWORD;
        }

        return $authenticated;
    }

    /**
     * Validate request location regarding geo-lockin
     *
     * @param string $ip IP address to check, usually request()->ip()
     */
    public function validateLocation($ip): bool
    {
        if (!\config('app.with_geolockin')) {
            return true;
        }

        $countryCodes = json_decode($this->getSetting('limit_geo', "[]"));

        if (empty($countryCodes)) {
            return true;
        }

        return in_array(Utils::countryForIP($ip), $countryCodes);
    }

    /**
     * Retrieve and authenticate a user
     *
     * @param string  $username   The username
     * @param string  $password   The password in plain text
     * @param ?string $clientIP   The IP address of the client
     * @param ?bool   $withChecks Enable MFA and location checks
     *
     * @return array ['user', 'reason', 'errorMessage']
     */
    public static function findAndAuthenticate($username, $password, $clientIP = null, $withChecks = true): array
    {
        $error = null;

        if (!$clientIP) {
            $clientIP = request()->ip();
        }

        $user = self::where('email', $username)->first();

        if (!$user) {
            $error = AuthAttempt::REASON_NOTFOUND;
        } else {
            if ($userid = AuthUtils::tokenValidate($password)) {
                if ($user->id == $userid) {
                    $withChecks = false;
                } else {
                    $error = AuthAttempt::REASON_PASSWORD;
                }
            } else {
                if ($user->role == self::ROLE_SERVICE) {
                    $withChecks = false;
                }

                if (!$withChecks) {
                    $cacheId = hash('sha256', "{$user->id}-{$password}");
                    // Skip the slow password verification for cases where we also don't verify mfa.
                    // We rely on this for fast cyrus-sasl authentication.
                    if (Cache::has($cacheId)) {
                        \Log::debug("Cached authentication for {$user->email}");
                        return ['user' => $user];
                    }
                }

                if (($vresult = $user->validatePassword($password)) !== true) {
                    $error = $vresult;
                }
            }
        }

        if ($withChecks) {
            // Check user (request) location
            if (!$error && !$user->validateLocation($clientIP)) {
                $error = AuthAttempt::REASON_GEOLOCATION;
            }

            // Check 2FA
            if (!$error) {
                try {
                    (new SecondFactor($user))->validate(request()->secondfactor);
                } catch (\Exception $e) {
                    $error = AuthAttempt::REASON_2FA_GENERIC;
                    $message = $e->getMessage();
                }
            }

            // Check 2FA - Companion App
            if (!$error && $user->isMFAEnabled()) {
                $attempt = AuthAttempt::recordAuthAttempt($user, $clientIP);
                if (!$attempt->waitFor2FA()) {
                    $error = AuthAttempt::REASON_2FA;
                }
            }
        }

        if ($error) {
            if ($user && empty($attempt)) {
                $attempt = AuthAttempt::recordAuthAttempt($user, $clientIP);
                if (!$attempt->isAccepted()) {
                    $attempt->deny($error);
                    $attempt->save();
                    $attempt->notify();
                }
            }

            if ($user) {
                \Log::info("Authentication failed for {$user->email}. Error: {$error}");
            }

            return ['reason' => $error, 'errorMessage' => $message ?? \trans("auth.error.{$error}")];
        }

        \Log::info("Successful authentication for {$user->email}");

        if (!empty($cacheId)) {
            // Cache for 60s
            Cache::put($cacheId, true, 60);
        }

        return ['user' => $user];
    }

    /**
     * Hook for passport
     *
     * @return User User model object if found
     *
     * @throws \Throwable
     */
    public static function findAndValidateForPassport($username, $password): self
    {
        $verifyMFA = true;
        if (request()->scope == "mfa") {
            \Log::info("Not validating MFA because this is a request for an mfa scope.");
            // Don't verify MFA if this is only an mfa token.
            // If we didn't do this, we couldn't pair backup devices.
            $verifyMFA = false;
        }

        $result = self::findAndAuthenticate($username, $password, null, $verifyMFA);

        if (isset($result['reason'])) {
            switch ($result['reason']) {
                case AuthAttempt::REASON_2FA_GENERIC:
                    $errorType = 'secondfactor'; // TODO: Can we just use $result['reason'] instead?
                    // no break
                case AuthAttempt::REASON_PASSWORD_EXPIRED:
                    // This results in a json response of {'error': $errorType, 'error_description': $errorMessage}
                    throw new OAuthServerException($result['errorMessage'], 6, $errorType ?? $result['reason'], 401);
                default:
                    // TODO: Display specific error message if 2FA via Companion App was expected?
                    throw OAuthServerException::invalidCredentials();
            }
        }

        return $result['user'];
    }
}
