<?php

namespace App;

use App\Traits\BelongsToTenantTrait;
use App\Traits\UuidStrKeyTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

/**
 * The eloquent definition of a Plan.
 *
 * A Plan is a grouping of packages, such as a "Family Plan".
 *
 * A "Family Plan" as such may exist of "2 or more Kolab packages",
 * and apply a discount for the third and further Kolab packages.
 *
 * @property string    $description
 * @property int       $discount_qty
 * @property int       $discount_rate
 * @property int       $free_months
 * @property bool      $hidden
 * @property string    $id
 * @property string    $mode          Plan signup mode (Plan::MODE_*)
 * @property string    $name
 * @property \DateTime $promo_from
 * @property \DateTime $promo_to
 * @property ?int      $tenant_id
 * @property string    $title
 */
class Plan extends Model
{
    use BelongsToTenantTrait;
    use HasTranslations;
    use UuidStrKeyTrait;

    public const MODE_EMAIL = 'email';
    public const MODE_TOKEN = 'token';
    public const MODE_MANDATE = 'mandate';

    /** @var bool Indicates if the model should be timestamped. */
    public $timestamps = false;

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = [
        'title',
        'hidden',
        'mode',
        'name',
        'description',
        // a start and end datetime for this promotion
        'promo_from',
        'promo_to',
        // discounts start at this quantity
        'discount_qty',
        // the rate of the discount for this plan
        'discount_rate',
        // minimum number of months this plan is for
        'months',
        // number of free months (trial)
        'free_months',
    ];

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'promo_from' => 'datetime:Y-m-d H:i:s',
        'promo_to' => 'datetime:Y-m-d H:i:s',
        'discount_qty' => 'integer',
        'discount_rate' => 'integer',
        'hidden' => 'boolean',
        'months' => 'integer',
        'free_months' => 'integer',
    ];

    /** @var array<int, string> Translatable properties */
    public $translatable = [
        'name',
        'description',
    ];

    /**
     * The list price for this plan at the minimum configuration.
     *
     * @return int the costs in cents
     */
    public function cost(): int
    {
        $costs = 0;

        // TODO: What about plan's discount_qty/discount_rate?

        foreach ($this->packages as $package) {
            $costs += $package->pivot->cost();
        }

        // TODO: What about plan's free_months?

        return $costs * $this->months;
    }

    /**
     * The relationship to packages.
     *
     * The plan contains one or more packages. Each package may have its minimum number (for
     * billing) or its maximum (to allow topping out "enterprise" customers on a "small business"
     * plan).
     *
     * @return BelongsToMany<Package, $this, PlanPackage>
     */
    public function packages()
    {
        return $this->belongsToMany(Package::class, 'plan_packages')
            ->using(PlanPackage::class)
            ->withPivot([
                'qty',
                'qty_min',
                'qty_max',
                'discount_qty',
                'discount_rate',
            ]);
    }

    /**
     * Checks if the plan has any type of domain SKU assigned.
     */
    public function hasDomain(): bool
    {
        foreach ($this->packages as $package) {
            if ($package->isDomain()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if the plan has a SKU assigned.
     */
    public function hasSku(string $class): bool
    {
        foreach ($this->packages as $package) {
            foreach ($package->skus as $sku) {
                if ($sku->handler_class::entitleableClass() == $class) {
                    return true;
                }
            }
        }

        return false;
    }
}
