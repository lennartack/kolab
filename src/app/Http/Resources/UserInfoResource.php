<?php

namespace App\Http\Resources;

use App\Http\Controllers\API\V4\UsersController;
use App\Plan;
use App\Providers\PaymentProvider;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * User information response
 *
 * @mixin User
 */
class UserInfoResource extends JsonResource
{
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
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $wallet = $this->resource->wallet();

        // IsLocked flag to lock the user to the Wallet page only
        $isLocked = !$this->resource->isActive() && $wallet->plan()?->mode == Plan::MODE_MANDATE;

        // Settings
        $keys = array_merge(self::USER_SETTINGS, ['password_expired', 'debug']);
        $settings = $this->resource->settings()->whereIn('key', $keys)->pluck('value', 'key')->all();

        // Status info
        $state = UsersController::objectState($this->resource);
        $statusInfo = UsersController::statusInfo($this->resource);

        // Information about wallets and accounts for access checks
        $wallets = $this->resource->wallets->map([$this, 'walletPropsMap'])->toArray();
        $accounts = $this->resource->accounts->map([$this, 'walletPropsMap'])->toArray();
        $wallet = $this->walletPropsMap($wallet);

        return [
            // User identifier
            'id' => $this->resource->id,
            // User email address
            'email' => $this->resource->email,
            // User status
            'status' => $this->resource->status,
            // User creation date-time
            'created_at' => (string) $this->resource->created_at,
            // User deletion date-time
            'deleted_at' => (string) $this->resource->deleted_at,

            // @var bool Is user active?
            'isActive' => $state['isActive'] ?? false,
            // @var bool Is user deleted?
            'isDeleted' => $state['isDeleted'] ?? false,
            // @var bool Is user degraded?
            'isDegraded' => $state['isDegraded'] ?? false,
            // @var bool Is account owner degraded?
            'isAccountDegraded' => $state['isAccountDegraded'] ?? false,
            // @var bool Readiness state
            'isReady' => $state['isReady'],
            // @var bool IMAP readiness state
            'isImapReady' => $state['isImapReady'] ?? false,
            // @var bool LDAP readiness state
            'isLdapReady' => $this->when(isset($state['isLdapReady']), $state['isLdapReady'] ?? false),
            // @var bool Is user locked?
            'isLocked' => $isLocked,
            // @var bool Is user restricted?
            'isRestricted' => $state['isRestricted'] ?? false,
            // @var bool Is user suspended?
            'isSuspended' => $state['isSuspended'] ?? false,

            // @var array<strig, mixed> User settings (first_name, last_name, phone, etc.)
            'settings' => $settings,
            // @var array Wallets controlled by the user
            'accounts' => $accounts,
            // @var array Wallets owned by the user
            'wallets' => $wallets,
            // @var array Wallet the user is in
            'wallet' => $wallet,
            // @var array Extended status/permissions information
            'statusInfo' => $statusInfo,
        ];
    }

    /**
     * Add more info to the wallet object output
     */
    public function walletPropsMap($wallet): array
    {
        $result = $wallet->toArray();

        if ($wallet->discount) {
            $result['discount'] = $wallet->discount->discount;
            $result['discount_description'] = $wallet->discount->description;
        }

        if ($wallet->user_id != $this->resource->id) {
            // FIXME: This one probably is relevant for an admin/reseller UI only
            $result['user_email'] = $wallet->owner->email;
        }

        $provider = PaymentProvider::factory($wallet);
        $result['provider'] = $provider->name();

        return $result;
    }
}
