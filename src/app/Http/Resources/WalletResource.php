<?php

namespace App\Http\Resources;

use App\Http\Controllers\Controller;
use App\Providers\PaymentProvider;
use App\Wallet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wallet response
 *
 * @mixin Wallet
 */
class WalletResource extends JsonResource
{
    public bool $extended = false;

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $provider = PaymentProvider::factory($this->resource);

        $discount = 0;
        $discount_description = '';

        if ($this->extended) {
            if ($this->resource->discount) {
                $discount = $this->resource->discount->discount;
                $discount_description = $this->resource->discount->description;
            }

            $mandate = new WalletMandateResource($this->resource->getMandate());
            $providerLink = $provider->customerLink($this->resource);
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
            // Payment provider name
            'provider' => $provider->name(),
            // Wallet state notice
            'notice' => $this->getWalletNotice(),

            // Wallet discount (percent)
            'discount' => $this->when($this->extended, $discount),
            // Wallet discount description
            'discount_description' => $this->when($this->extended, $discount_description),
            // Recurring payment mandate information
            'mandate' => $this->when($this->extended, $mandate ?? null),
            // Link to the customer page at the payment provider site
            'providerLink' => $this->when($this->extended, $providerLink ?? null),
        ];
    }

    /**
     * Returns human readable notice about the wallet state.
     */
    protected function getWalletNotice(): ?string
    {
        // there is no credit
        if ($this->resource->balance < 0) {
            return Controller::trans('app.wallet-notice-nocredit');
        }

        // the discount is 100%, no credit is needed
        if ($this->resource->discount && $this->resource->discount->discount == 100) {
            return null;
        }

        $plan = $this->resource->plan();
        $freeMonths = $plan ? $plan->free_months : 0;
        $trialEnd = $freeMonths ? $this->resource->owner->created_at->copy()->addMonthsWithoutOverflow($freeMonths) : null;

        // the owner is still in the trial period
        if ($trialEnd && $trialEnd > Carbon::now()) {
            // notice of trial ending if less than 2 weeks left
            if ($trialEnd < Carbon::now()->addWeeks(2)) {
                return Controller::trans('app.wallet-notice-trial-end');
            }

            return Controller::trans('app.wallet-notice-trial');
        }

        if ($until = $this->resource->balanceLastsUntil()) {
            if ($until->isToday()) {
                return Controller::trans('app.wallet-notice-today');
            }

            // Once in a while we got e.g. "3 weeks" instead of expected "4 weeks".
            // It's because $until uses full seconds, but $now is more precise.
            // We make sure both have the same time set.
            $now = Carbon::now()->setTimeFrom($until);

            $diffOptions = [
                'syntax' => Carbon::DIFF_ABSOLUTE,
                'parts' => 1,
            ];

            if ($now->diffAsDateInterval($until)->days > 31) {
                $diffOptions['parts'] = 2;
            }

            $params = [
                'date' => $until->toDateString(),
                'days' => $now->diffForHumans($until, $diffOptions),
            ];

            return Controller::trans('app.wallet-notice-date', $params);
        }

        return null;
    }
}
