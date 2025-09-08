<?php

namespace App;

use App\Jobs\Mail\PaymentMandateDisabledJob;
use App\Providers\PaymentProvider;
use App\Traits\SettingsTrait;
use App\Traits\UuidStrKeyTrait;
use Carbon\Carbon;
use Dyrynda\Database\Support\NullableFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * The eloquent definition of a wallet -- a container with a chunk of change.
 *
 * A wallet is owned by an {@link \App\User}.
 *
 * @property int     $balance     Current balance in cents
 * @property string  $currency    Currency code
 * @property ?string $description Description
 * @property string  $id          Unique identifier
 * @property ?User   $owner       Owner (can be null when owner is deleted)
 * @property int     $user_id     Owner's identifier
 */
class Wallet extends Model
{
    use NullableFields;
    use SettingsTrait;
    use UuidStrKeyTrait;

    /** @var bool Indicates that the model should be timestamped or not */
    public $timestamps = false;

    /** @var array<string, mixed> The attributes' default values */
    protected $attributes = [
        'balance' => 0,
    ];

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = [
        'currency',
        'description',
    ];

    /** @var array<int, string> The attributes that can be not set */
    protected $nullable = [
        'description',
    ];

    /** @var array<string, string> The types of attributes to which its values will be cast */
    protected $casts = [
        'balance' => 'integer',
    ];

    /**
     * Add a controller to this wallet.
     *
     * @param User $user the user to add as a controller to this wallet
     */
    public function addController(User $user)
    {
        if (!$this->controllers->contains($user)) {
            $this->controllers()->save($user);
            $this->unsetRelation('controllers');
        }
    }

    /**
     * Add an award to this wallet's balance.
     *
     * @param int|Payment $amount      The amount of award (in cents) or Payment object
     * @param string      $description The transaction description
     *
     * @return Wallet Self
     */
    public function award(int|Payment $amount, string $description = ''): self
    {
        return $this->balanceUpdate(Transaction::WALLET_AWARD, $amount, $description);
    }

    /**
     * Charge entitlements in the wallet
     *
     * @param bool $apply Set to false for a dry-run mode
     *
     * @return int Charged amount in cents
     */
    public function chargeEntitlements($apply = true): int
    {
        $transactions = [];
        $profit = 0;
        $charges = 0;
        $isDegraded = $this->owner->isDegraded();
        $trial = $this->trialInfo();

        if ($apply) {
            DB::beginTransaction();
        }

        // Get all relevant entitlements...
        $entitlements = $this->entitlements()->withTrashed()
            ->where(static function (Builder $query) {
                // existing entitlements created, or billed last less than a month ago
                $query->where(static function (Builder $query) {
                    $query->whereNull('deleted_at')
                        ->where('updated_at', '<=', Carbon::now()->subMonthsWithoutOverflow(1));
                })
                // deleted entitlements not yet charged
                    ->orWhere(static function (Builder $query) {
                        $query->whereColumn('updated_at', '<', 'deleted_at');
                    });
            })
            ->get();

        foreach ($entitlements as $entitlement) {
            // Calculate cost, fee, and end of period
            [$cost, $fee, $endDate] = $this->entitlementCosts($entitlement, $trial);

            // Note: Degraded pays nothing, but we get the money from a tenant.
            // Therefore $cost = 0, but $profit < 0.
            if ($isDegraded) {
                $cost = 0;
            }

            $charges += $cost;
            $profit += $cost - $fee;

            // if we're in dry-run, you know...
            if (!$apply) {
                continue;
            }

            if ($endDate) {
                $entitlement->updated_at = $endDate;
                $entitlement->save();
            }

            if ($cost == 0) {
                continue;
            }

            $transactions[] = $entitlement->createTransaction(Transaction::ENTITLEMENT_BILLED, $cost);
        }

        if ($apply) {
            $this->debit($charges, '', $transactions)->addTenantProfit($profit);

            DB::commit();
        }

        return $charges;
    }

    /**
     * Calculate for how long the current balance will last.
     *
     * Returns NULL for balance < 0 or discount = 100% or on a fresh account
     *
     * @return Carbon|null Date
     */
    public function balanceLastsUntil()
    {
        if ($this->balance < 0 || $this->getDiscount() == 100) {
            return null;
        }

        $balance = $this->balance;
        $discount = $this->getDiscountRate();
        $trial = $this->trialInfo();

        // Get all entitlements...
        $entitlements = $this->entitlements()->orderBy('updated_at')->get()
            ->filter(static function ($entitlement) {
                return $entitlement->cost > 0;
            })
            ->map(static function ($entitlement) {
                return [
                    'date' => $entitlement->updated_at ?: $entitlement->created_at,
                    'cost' => $entitlement->cost,
                    'sku_id' => $entitlement->sku_id,
                ];
            })
            ->all();

        $max = 12 * 25;
        while ($max > 0) {
            foreach ($entitlements as &$entitlement) {
                $until = $entitlement['date'] = $entitlement['date']->addMonthsWithoutOverflow(1);

                if (
                    !empty($trial)
                    && $entitlement['date'] < $trial['end']
                    && in_array($entitlement['sku_id'], $trial['skus'])
                ) {
                    continue;
                }

                $balance -= (int) ($entitlement['cost'] * $discount);

                if ($balance < 0) {
                    break 2;
                }
            }

            $max--;
        }

        if (empty($until)) {
            return null;
        }

        // Don't return dates from the past
        if ($until <= Carbon::now() && !$until->isToday()) {
            return null;
        }

        return $until;
    }

    /**
     * Chargeback an amount of pecunia from this wallet's balance.
     *
     * @param int|Payment $amount      The amount of pecunia to charge back (in cents) or Payment object
     * @param string      $description The transaction description
     *
     * @return Wallet Self
     */
    public function chargeback(int|Payment $amount, string $description = ''): self
    {
        return $this->balanceUpdate(Transaction::WALLET_CHARGEBACK, $amount, $description);
    }

    /**
     * Controllers of this wallet.
     *
     * @return BelongsToMany<User, $this>
     */
    public function controllers()
    {
        return $this->belongsToMany(
            User::class,      // The foreign object definition
            'user_accounts',  // The table name
            'wallet_id',      // The local foreign key
            'user_id'         // The remote foreign key
        );
    }

    /**
     * Add an amount of pecunia to this wallet's balance.
     *
     * @param int|Payment $amount      The amount of pecunia to add (in cents) or Payment object
     * @param string      $description The transaction description
     *
     * @return Wallet Self
     */
    public function credit(int|Payment $amount, string $description = ''): self
    {
        return $this->balanceUpdate(Transaction::WALLET_CREDIT, $amount, $description);
    }

    /**
     * Deduct an amount of pecunia from this wallet's balance.
     *
     * @param int|Payment $amount      The amount of pecunia to deduct (in cents) or Payment object
     * @param string      $description The transaction description
     * @param array       $eTIDs       list of transaction IDs for the individual entitlements
     *                                 that make up this debit record, if any
     *
     * @return Wallet Self
     */
    public function debit(int|Payment $amount, string $description = '', array $eTIDs = []): self
    {
        return $this->balanceUpdate(Transaction::WALLET_DEBIT, $amount, $description, $eTIDs);
    }

    /**
     * The discount assigned to the wallet.
     *
     * @return BelongsTo<Discount, $this>
     */
    public function discount()
    {
        return $this->belongsTo(Discount::class, 'discount_id', 'id');
    }

    /**
     * Entitlements billed to this wallet.
     *
     * @return HasMany<Entitlement, $this>
     */
    public function entitlements()
    {
        return $this->hasMany(Entitlement::class);
    }

    /**
     * Calculate the expected charges to this wallet.
     *
     * @return int
     */
    public function expectedCharges()
    {
        return $this->chargeEntitlements(false);
    }

    /**
     * Return the exact, numeric version of the discount to be applied.
     *
     * @return int discount in percent, ranges from 0 - 100
     */
    public function getDiscount(): int
    {
        return $this->discount ? $this->discount->discount : 0;
    }

    /**
     * The actual discount rate for use in multiplication
     *
     * @return float Discount rate, ranges from 0.00 to 1.00.
     */
    public function getDiscountRate(): float
    {
        return (100 - $this->getDiscount()) / 100;
    }

    /**
     * The minimum amount of an auto-payment mandate
     *
     * @return int Amount in cents
     */
    public function getMinMandateAmount(): int
    {
        $min = Payment::MIN_AMOUNT;

        if ($plan = $this->plan()) {
            $planCost = (int) ($plan->cost() * $this->getDiscountRate());

            if ($planCost > $min) {
                $min = $planCost;
            }
        }

        return $min;
    }

    /**
     * Check if the specified user is a controller to this wallet.
     *
     * @param User $user the user object
     *
     * @return bool True if the user is one of the wallet controllers (including user), False otherwise
     */
    public function isController(User $user): bool
    {
        return $user->id == $this->user_id || $this->controllers->contains($user);
    }

    /**
     * A helper to display human-readable amount of money using
     * the wallet currency and specified locale.
     *
     * @param int    $amount A amount of money (in cents)
     * @param string $locale A locale for the output
     *
     * @return string String representation, e.g. "9.99 CHF"
     */
    public function money(int $amount, $locale = 'de_DE')
    {
        return Utils::money($amount, $this->currency, $locale);
    }

    /**
     * The owner of the wallet -- the wallet is in his/her back pocket.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Prepare a payment. Calculates tax for the payment, fills the request with additional properties.
     *
     * @param array $request The request data with the payment amount
     */
    public function paymentRequest(array $request): array
    {
        $request['vat_rate_id'] = null;
        $request['credit_amount'] = $request['amount'];

        if ($rate = $this->vatRate()) {
            $request['vat_rate_id'] = $rate->id;

            switch (\config('app.vat.mode')) {
                case 1:
                    // In this mode tax is added on top of the payment. The amount
                    // to pay grows, but we keep wallet balance without tax.
                    $request['amount'] += round($request['amount'] * $rate->rate / 100);
                    break;
                default:
                    // In this mode tax is "swallowed" by the vendor. The payment
                    // amount does not change
                    break;
            }
        }

        return $request;
    }

    /**
     * Payments on this wallet.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Add a penalty to this wallet's balance.
     *
     * @param int|Payment $amount      The amount of penalty (in cents) or Payment object
     * @param string      $description The transaction description
     *
     * @return Wallet Self
     */
    public function penalty(int|Payment $amount, string $description = ''): self
    {
        return $this->balanceUpdate(Transaction::WALLET_PENALTY, $amount, $description);
    }

    /**
     * Plan of the wallet.
     *
     * @return ?Plan
     */
    public function plan()
    {
        $planId = $this->owner->getSetting('plan_id');

        return $planId ? Plan::find($planId) : null;
    }

    /**
     * Remove a controller from this wallet.
     *
     * @param User $user the user to remove as a controller from this wallet
     */
    public function removeController(User $user)
    {
        if ($this->controllers->contains($user)) {
            $this->controllers()->detach($user);
            $this->unsetRelation('controllers');
        }
    }

    /**
     * Refund an amount of pecunia from this wallet's balance.
     *
     * @param int|Payment $amount      The amount of pecunia to refund (in cents) or Payment object
     * @param string      $description The transaction description
     *
     * @return Wallet Self
     */
    public function refund($amount, string $description = ''): self
    {
        return $this->balanceUpdate(Transaction::WALLET_REFUND, $amount, $description);
    }

    /**
     * Top up a wallet with a "recurring" payment.
     *
     * @return bool True if the payment has been initialized
     */
    public function topUp(): bool
    {
        $settings = $this->getSettings(['mandate_disabled', 'mandate_balance', 'mandate_amount']);

        \Log::debug("Requested top-up for wallet {$this->id}");

        if (!empty($settings['mandate_disabled'])) {
            \Log::debug("Top-up for wallet {$this->id}: mandate disabled");
            return false;
        }

        $min_balance = (int) round((float) $settings['mandate_balance'] * 100);
        $amount = (int) round((float) $settings['mandate_amount'] * 100);

        // The wallet balance is greater than the auto-payment threshold
        if ($this->balance >= $min_balance) {
            // Do nothing
            return false;
        }

        $provider = PaymentProvider::factory($this);
        $mandate = (array) $provider->getMandate($this);

        if (empty($mandate['isValid'])) {
            \Log::warning("Top-up for wallet {$this->id}: mandate invalid");
            if (empty($mandate['isPending'])) {
                \Log::warning("Disabling mandate");
                $this->setSetting('mandate_disabled', '1');
            }

            return false;
        }

        // The defined top-up amount is not enough
        // Disable auto-payment and notify the user
        if ($this->balance + $amount < 0) {
            // Disable (not remove) the mandate
            \Log::warning("Top-up for wallet {$this->id}: mandate too little");
            $this->setSetting('mandate_disabled', '1');
            PaymentMandateDisabledJob::dispatch($this);
            return false;
        }

        $appName = Tenant::getConfig($this->owner->tenant_id, 'app.name');
        $description = "{$appName} Recurring Payment";
        if ($plan = $this->plan()) {
            if ($plan->months == 12) {
                $description = "{$appName} Annual Payment";
            } elseif ($plan->months == 3) {
                $description = "{$appName} Quarterly Payment";
            } elseif ($plan->months == 1) {
                $description = "{$appName} Monthly Payment";
            }
        }

        $request = $this->paymentRequest([
            'type' => Payment::TYPE_RECURRING,
            'currency' => $this->currency,
            'amount' => $amount,
            'methodId' => PaymentProvider::METHOD_CREDITCARD,
            'description' => $description,
        ]);

        $result = $provider->payment($this, $request);

        return !empty($result);
    }

    /**
     * Get the VAT rate for the wallet owner country.
     *
     * @param ?\DateTime $start get the rate valid for the specified date-time,
     *                          without it the current rate will be returned (if exists)
     *
     * @return ?VatRate VAT rate
     */
    public function vatRate(?\DateTime $start = null): ?VatRate
    {
        $owner = $this->owner;

        // Make it working with deleted accounts too
        if (!$owner) {
            $owner = $this->owner()->withTrashed()->first();
        }

        $country = $owner->getSetting('country');

        if (!$country) {
            return null;
        }

        return VatRate::where('country', $country)
            ->where('start', '<=', ($start ?: now())->format('Y-m-d h:i:s'))
            ->orderByDesc('start')
            ->limit(1)
            ->first();
    }

    /**
     * Retrieve the transactions against this wallet.
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'object_id')
            ->where('object_type', self::class);
    }

    /**
     * Returns trial related information.
     *
     * @return ?array Plan ID, plan SKUs, trial end date, number of free months (planId, skus, end, months)
     */
    public function trialInfo(): ?array
    {
        $plan = $this->plan();
        $freeMonths = $plan ? $plan->free_months : 0;
        $trialEnd = $freeMonths ? $this->owner->created_at->copy()->addMonthsWithoutOverflow((int) $freeMonths) : null;

        if ($trialEnd) {
            // Get all SKUs assigned to the plan (they are free in trial)
            // TODO: We could store the list of plan's SKUs in the wallet settings, for two reasons:
            //       - performance
            //       - if we change plan definition at some point in time, the old users would use
            //         the old definition, instead of the current one
            // TODO: The same for plan's free_months value
            $trialSkus = Sku::select('id')
                ->whereIn('id', static function ($query) use ($plan) {
                    $query->select('sku_id')
                        ->from('package_skus')
                        ->whereIn('package_id', static function ($query) use ($plan) {
                            $query->select('package_id')
                                ->from('plan_packages')
                                ->where('plan_id', $plan->id);
                        });
                })
                ->whereNot('title', 'storage')
                ->pluck('id')
                ->all();

            return [
                'end' => $trialEnd,
                'skus' => $trialSkus,
                'planId' => $plan->id,
                'months' => $freeMonths,
            ];
        }

        return null;
    }

    /**
     * Force-update entitlements' updated_at, charge if needed.
     *
     * @param bool $withCost When enabled the cost will be charged
     *
     * @return int Charged amount in cents
     */
    public function updateEntitlements($withCost = true): int
    {
        $charges = 0;
        $profit = 0;
        $trial = $this->trialInfo();

        DB::beginTransaction();

        $transactions = [];

        $entitlements = $this->entitlements()->where('updated_at', '<', Carbon::now())->get();

        foreach ($entitlements as $entitlement) {
            // Calculate cost, fee, and end of period
            [$cost, $fee, $endDate] = $this->entitlementCosts($entitlement, $trial, true);

            // Note: Degraded pays nothing, but we get the money from a tenant.
            // Therefore $cost = 0, but $profit < 0.
            if (!$withCost) {
                $cost = 0;
            }

            if ($endDate) {
                $entitlement->updated_at = $entitlement->updated_at->setDateFrom($endDate);
                $entitlement->save();
            }

            $charges += $cost;
            $profit += $cost - $fee;

            if ($cost == 0) {
                continue;
            }

            // FIXME: Shouldn't we store also cost=0 transactions (to have the full history)?
            $transactions[] = $entitlement->createTransaction(Transaction::ENTITLEMENT_BILLED, $cost);
        }

        $this->debit($charges, '', $transactions)->addTenantProfit($profit);

        DB::commit();

        return $charges;
    }

    /**
     * Add profit to the tenant's wallet
     *
     * @param int $profit Profit amount (in cents), can be negative
     */
    protected function addTenantProfit($profit): void
    {
        // Credit/debit the reseller
        if ($profit != 0 && $this->owner->tenant) {
            // FIXME: Should we have a simpler way to skip this for non-reseller tenant(s)
            if ($wallet = $this->owner->tenant->wallet()) {
                $desc = "Charged user {$this->owner->email}";
                if ($profit > 0) {
                    $wallet->credit(abs($profit), $desc);
                } else {
                    $wallet->debit(abs($profit), $desc);
                }
            }
        }
    }

    /**
     * Update the wallet balance, and create a transaction record
     */
    protected function balanceUpdate(string $type, int|Payment $amount, $description = null, array $eTIDs = [])
    {
        if ($amount instanceof Payment) {
            $amount = $amount->credit_amount;
        }

        if ($amount === 0) {
            return $this;
        }

        if (in_array($type, [Transaction::WALLET_CREDIT, Transaction::WALLET_AWARD])) {
            $amount = abs($amount);
        } else {
            $amount = abs($amount) * -1;
        }

        $this->balance += $amount;
        $this->save();

        $transaction = Transaction::create([
            'user_email' => Utils::userEmailOrNull(),
            'object_id' => $this->id,
            'object_type' => self::class,
            'type' => $type,
            'amount' => $amount,
            'description' => $description,
        ]);

        if (!empty($eTIDs)) {
            Transaction::whereIn('id', $eTIDs)->update(['transaction_id' => $transaction->id]);
        }

        return $this;
    }

    /**
     * Calculate entitlement cost/fee for the current charge
     *
     * @param Entitlement $entitlement   Entitlement object
     * @param array|null  $trial         Trial information (result of Wallet::trialInfo())
     * @param bool        $useCostPerDay Force calculation based on a per-day cost
     *
     * @return array Result in form of [cost, fee, end-of-period]
     */
    protected function entitlementCosts(Entitlement $entitlement, ?array $trial = null, bool $useCostPerDay = false)
    {
        if ($entitlement->wallet_id != $this->id) {
            throw new \Exception("Entitlement assigned to another wallet");
        }

        $discountRate = $this->getDiscountRate();
        $startDate = $entitlement->updated_at;  // start of the period to charge for
        $endDate = Carbon::now();               // end of the period to charge for

        // Deleted entitlements are always charged for all uncharged days up to the delete date
        if ($entitlement->trashed()) {
            $useCostPerDay = true;
            $endDate = $entitlement->deleted_at->copy();
        }

        // Consider Trial period
        if (!empty($trial) && $startDate < $trial['end'] && in_array($entitlement->sku_id, $trial['skus'])) {
            if ($trial['end'] > $endDate) {
                return [0, 0, $trial['end']];
            }

            $startDate = $trial['end'];
        }

        if ($useCostPerDay) {
            // Note: In this mode we need a full cost including partial periods.

            // Anything's free for the first 14 days.
            if ($entitlement->created_at >= $endDate->copy()->subDays(14)) {
                return [0, 0, $endDate];
            }

            $cost = 0;
            $fee = 0;

            // Charge for full months first
            if (($diff = (int) $startDate->diffInMonths($endDate, true)) > 0) {
                $cost += floor($entitlement->cost * $discountRate) * $diff;
                $fee += $entitlement->fee * $diff;
                $startDate->addMonthsWithoutOverflow($diff);
            }

            // Charge for the rest of the period
            if (($diff = (int) $startDate->diffInDays($endDate, true)) > 0) {
                // The price per day is based on the number of days in the month(s)
                // Note: The $endDate does not have to be the current month
                $endMonthDiff = $endDate->day > $diff ? $diff : $endDate->day;
                $startMonthDiff = $diff - $endMonthDiff;

                // FIXME: This could be calculated in a few different ways, e.g. rounding or flooring
                // the daily cost first and then applying discount and number of days. This could lead
                // to very small values in some cases resulting in a zero result.
                $cost += floor($entitlement->cost / $endDate->daysInMonth * $discountRate * $endMonthDiff);
                $fee += floor($entitlement->fee / $endDate->daysInMonth * $endMonthDiff);

                if ($startMonthDiff) {
                    $cost += floor($entitlement->cost / $startDate->daysInMonth * $discountRate * $startMonthDiff);
                    $fee += floor($entitlement->fee / $startDate->daysInMonth * $startMonthDiff);
                }
            }
        } else {
            // Note: In this mode we expect to charge the entitlement for full month(s) only
            $diff = (int) $startDate->diffInMonths($endDate, true);

            if ($diff <= 0) {
                // Do not update updated_at column (not a full month) unless trial end date
                // is after current updated_at date
                return [0, 0, $startDate != $entitlement->updated_at ? $startDate : null];
            }

            $endDate = $startDate->addMonthsWithoutOverflow($diff);

            $cost = floor($entitlement->cost * $discountRate) * $diff;
            $fee = $entitlement->fee * $diff;
        }

        return [(int) $cost, (int) $fee, $endDate];
    }

    /**
     * Returns auto-payment mandate info for the specified wallet
     */
    public function getMandate(): array
    {
        $provider = PaymentProvider::factory($this);
        $settings = $this->getSettings(['mandate_disabled', 'mandate_balance', 'mandate_amount']);

        // Get the Mandate info
        $mandate = (array) $provider->getMandate($this);

        $mandate['amount'] = $mandate['minAmount'] = round($this->getMinMandateAmount() / 100, 2);
        $mandate['balance'] = 0;
        $mandate['isDisabled'] = !empty($mandate['id']) && $settings['mandate_disabled'];
        $mandate['isValid'] = !empty($mandate['isValid']);

        foreach (['amount', 'balance'] as $key) {
            if (($value = $settings["mandate_{$key}"]) !== null) {
                $mandate[$key] = $value;
            }
        }

        // Unrestrict the wallet owner if mandate is valid
        if (!empty($mandate['isValid']) && $this->owner->isRestricted()) {
            $this->owner->unrestrict();
        }

        return $mandate;
    }
}
