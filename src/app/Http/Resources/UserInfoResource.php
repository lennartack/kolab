<?php

namespace App\Http\Resources;

use App\Http\Controllers\API\V4\UsersController;
use App\Plan;
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
        'external_email_new',
        'external_email_code',
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

        return [
            $this->merge(parent::toArray($request)),

            // @var bool Is user locked?
            'isLocked' => $isLocked,

            // @var array<strig, mixed> User settings (first_name, last_name, phone, etc.)
            'settings' => $settings,
            // Wallets controlled by the user
            'accounts' => WalletResource::collection($this->resource->accounts),
            // Wallets owned by the user
            'wallets' => WalletResource::collection($this->resource->wallets),
            // @var array Extended status/permissions information
            'statusInfo' => UsersController::statusInfo($this->resource),

            // Entitlements/Wallet information
            $this->merge(self::objectEntitlements($this->resource)),
        ];
    }
}
