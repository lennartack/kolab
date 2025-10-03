<?php

namespace App\Http\Resources;

use App\Transaction;
use App\Wallet;
use Illuminate\Http\Request;

/**
 * Transaction response
 *
 * @mixin Transaction
 */
class TransactionResource extends ApiResource
{
    public ?Wallet $wallet;

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            // Transaction identifier
            'id' => $this->resource->id,
            // Transaction date (Y-m-d H:i)
            'createdAt' => $this->resource->created_at->format('Y-m-d H:i'),
            // Transaction type
            'type' => $this->resource->type,
            // Transaction description
            'description' => $this->resource->shortDescription(),
            // @var int Transaction amount (in cents)
            'amount' => $this->resource->amount,
            // Transaction currency
            'currency' => $this->wallet->currency,

            // @var bool Indicates that the entry has sub-transactions
            'hasDetails' => !empty($this->resource->cnt),

            // Acting user email address
            'user' => $this->when(self::isAdmin(), $this->resource->user_email),
        ];
    }
}
