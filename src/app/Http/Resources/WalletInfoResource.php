<?php

namespace App\Http\Resources;

use App\Http\Controllers\Controller;
use App\Providers\PaymentProvider;
use App\Wallet;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Wallet information response
 *
 * @mixin Wallet
 */
class WalletInfoResource extends WalletResource
{
    protected ?string $next_payment_date;

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        if ($isAdmin = self::isAdmin()) {
            $provider = PaymentProvider::factory($this->resource);
            $mandate = new WalletMandateResource($this->resource->getMandate());
            $providerLink = $provider->customerLink($this->resource);
        }

        $notice = $this->getWalletNotice();

        return [
            $this->merge(parent::toArray($request)),

            // @var string|null Next payment date (Y-m-d) if expected
            'nextPaymentDate' => $this->next_payment_date,
            // @var string|null Wallet status notice
            'notice' => $notice,
            // @var WalletMandateResource Recurring payment mandate information
            'mandate' => $this->when($isAdmin, $mandate ?? null),
            // Link to the customer page at the payment provider site
            'providerLink' => $this->when($isAdmin, $providerLink ?? null),
        ];
    }

    /**
     * Returns human readable notice about the wallet state.
     */
    protected function getWalletNotice(): ?string
    {
        $this->next_payment_date = null;

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
            $this->next_payment_date = $until->toDateString();

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
