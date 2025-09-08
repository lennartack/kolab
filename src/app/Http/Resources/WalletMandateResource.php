<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wallet mandate response
 */
class WalletMandateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            // @var float Money amount for a recurring payment
            'amount' => $this->resource['amount'] ?? 0,
            // @var float Minimum money amount for a recurring payment
            'minAmount' => $this->resource['minAmount'] ?? 0,
            // @var float Minimum wallet balance at which a recurring payment will be made
            'balance' => $this->resource['balance'] ?? 0,
            // @var string|null Mandate identifier (if exists)
            'id' => $this->resource['id'] ?? null,
            // @var bool Is the mandate existing, but disabled?
            'isDisabled' => $this->resource['isDisabled'] ?? false,
            // @var bool Is the mandate existing, but pending?
            'isPending' => $this->resource['isPending'] ?? false,
            // @var bool Is the mandate existing and valid?
            'isValid' => $this->resource['isValid'] ?? false,
            // @var string|null Payment method name
            'method' => $this->resource['method'] ?? null,
            // @var string|null Payment method identifier
            'methodId' => $this->resource['method'] ?? null,
        ];
    }
}
