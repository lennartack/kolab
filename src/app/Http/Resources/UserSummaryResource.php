<?php

namespace App\Http\Resources;

use App\Providers\PaymentProvider;
use Illuminate\Http\Request;

/**
 * User summary response
 */
class UserSummaryResource extends UserResource
{
    /** @var array List of user setting keys in a response */
    public const USER_SETTINGS = [
        'billing_address',
        'country',
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
        $isOwner = $wallet->user_id == $this->resource->id;
        $isAdmin = self::isAdmin();

        // Payment provider info
        if ($isOwner && $isAdmin) {
            $provider = PaymentProvider::factory($wallet);
            $providerName = $provider->name();
            $providerLink = $provider->customerLink($wallet);
        }

        // User settings
        $settings = $this->resource->settings()->whereIn('key', self::USER_SETTINGS)->pluck('value', 'key')->all();

        return [
            $this->merge(parent::toArray($request)),

            // @var array<strig, mixed> User settings (first_name, last_name, phone, etc.)
            'settings' => $settings,

            // Payment provider name
            'provider' => $this->when($isOwner && $isAdmin, $providerName ?? null),

            // Link to the customer page at the payment provider site
            'providerLink' => $this->when($isOwner && $isAdmin, $providerLink ?? null),

            // Wallet the user is in
            'wallet' => new WalletResource($wallet),
        ];
    }
}
