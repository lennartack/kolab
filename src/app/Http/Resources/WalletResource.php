<?php

namespace App\Http\Resources;

use App\Providers\PaymentProvider;
use App\Wallet;
use Illuminate\Http\Request;

/**
 * Wallet response
 *
 * @mixin Wallet
 */
class WalletResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $provider = PaymentProvider::factory($this->resource);
        $discount = 0;
        $discount_description = '';

        if ($this->resource->discount) {
            $discount = $this->resource->discount->discount;
            $discount_description = $this->resource->discount->description;
        }

        return [
            // Wallet identifier
            'id' => $this->resource->id,
            // Wallet balance (in cents)
            'balance' => $this->resource->balance,
            // Wallet currency
            'currency' => $this->resource->currency,
            // Wallet description
            'description' => $this->resource->description,
            // Wallet owner (user identifier)
            'user_id' => $this->resource->user_id,
            // Wallet owner (email address)
            'user_email' => $this->when(self::isAdmin(), $this->resource->owner()->withTrashed()->first()?->email),
            // Payment provider name
            'provider' => $provider->name(),
            // Wallet discount identifier (if any)
            'discount_id' => $this->resource->discount_id,
            // Wallet discount (percent)
            'discount' => $discount,
            // @var string Wallet discount description
            'discount_description' => $discount_description,
        ];
    }
}
