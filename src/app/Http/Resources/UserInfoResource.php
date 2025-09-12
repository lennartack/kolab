<?php

namespace App\Http\Resources;

use App\Http\Controllers\API\V4\UsersController;
use App\Plan;
use App\Providers\PaymentProvider;
use App\User;
use Illuminate\Http\Request;

/**
 * User information response
 */
class UserInfoResource extends UserResource
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
        $statusInfo = UsersController::statusInfo($this->resource);

        // Information about wallets and accounts for access checks
        $wallets = $this->resource->wallets->map([$this, 'walletPropsMap'])->toArray();
        $accounts = $this->resource->accounts->map([$this, 'walletPropsMap'])->toArray();
        $wallet = $this->walletPropsMap($wallet);

        return [
            $this->merge(parent::toArray($request)),

            // @var bool Is user locked?
            'isLocked' => $isLocked,

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
