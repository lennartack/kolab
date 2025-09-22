<?php

namespace App;

use App\Traits\BelongsToTenantTrait;
use App\Traits\EntitleableTrait;
use App\Traits\UuidIntKeyTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * The eloquent definition of a Device
 *
 * @property string $hash
 * @property int    $id
 * @property int    $tenant_id
 */
class Device extends Model
{
    use BelongsToTenantTrait;
    use EntitleableTrait;
    use SoftDeletes;
    use UuidIntKeyTrait;

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = [
        'hash',
    ];

    /**
     * Assign a package plan to a device.
     *
     * @param Plan   $plan   The plan to assign
     * @param Wallet $wallet The wallet to use
     *
     * @throws \Exception
     */
    public function assignPlan(Plan $plan, Wallet $wallet): void
    {
        $device_packages = $plan->packages->filter(static function ($package) {
            foreach ($package->skus as $sku) {
                if ($sku->handler_class::entitleableClass() == self::class) {
                    return true;
                }
            }

            return false;
        });

        // Before we do anything let's make sure that a device can be assigned only
        // to a plan with a device SKU in a package
        if ($device_packages->count() != 1) {
            throw new \Exception("A device requires a plan with a device SKU");
        }

        foreach ($device_packages as $package) {
            $this->assignPackageAndWallet($package, $wallet);
        }
    }

    /**
     * Assign device to (another) real user account
     */
    public function bindTo(User $user): void
    {
        $wallet = $user->wallets()->first();

        // TODO: What if the device is already used by another (real) user?
        DB::beginTransaction();

        // Remove existing user association
        $this->entitlements()->delete();

        $device_packages = [];

        // Existing user's plan
        if ($plan_id = $user->getSetting('plan_id')) {
            $plan = Plan::withObjectTenantContext($user)->find($plan_id);

            // Find packages with a device SKU in this plan
            $device_packages = !$plan ? collect([]) : $plan->packages->filter(static function ($package) {
                foreach ($package->skus as $sku) {
                    if ($sku->handler_class::entitleableClass() == self::class) {
                        return true;
                    }
                }

                return false;
            });

            if ($device_packages->count() != 1) {
                throw new \Exception("A device requires a plan with a device SKU");
            }
        }

        // TODO: Get "default" device package and assign if none found above, or just use the device SKU?

        foreach ($device_packages as $package) {
            $this->assignPackageAndWallet($package, $wallet);
        }

        // Push entitlements.updated_at to one year from the first registration
        $threshold = (clone $this->created_at)->addYearWithoutOverflow();
        if ($threshold > \now()) {
            $this->entitlements()->each(static function ($entitlement) use ($threshold) {
                $entitlement->updated_at = $threshold;
                $entitlement->save();
            });
        }

        DB::commit();
    }

    /**
     * Signup a device
     */
    public static function signup(string $token, Plan $plan, string $password): self
    {
        DB::beginTransaction();

        // Create a device record
        $device = self::create(['hash' => $token]);

        // Create a special account
        while (true) {
            $user_id = Utils::uuidInt();
            if (!User::withTrashed()->where('id', $user_id)->orWhere('email', $user_id . '@' . \config('app.domain'))->exists()) {
                break;
            }
        }

        $user = new User();
        $user->id = $user_id;
        $user->email = $user_id . '@' . \config('app.domain');
        $user->password = $password;
        $user->role = User::ROLE_DEVICE;
        $user->save();

        $user->settings()->insert([
            ['key' => 'signup_token', 'value' => $token, 'user_id' => $user->id],
            ['key' => 'plan_id', 'value' => $plan->id, 'user_id' => $user->id],
        ]);

        // Assign the device via an entitlement to the user's wallet
        $device->assignPlan($plan, $wallet = $user->wallets()->first());

        // Push entitlements.updated_at to one year in the future
        $device->entitlements()->each(static function ($entitlement) {
            $entitlement->updated_at = \now()->addYearWithoutOverflow();
            $entitlement->save();
        });

        // TODO: Trigger payment mandate creation?

        DB::commit();

        return $device;
    }

    /**
     * Returns entitleable object title (e.g. email or domain name).
     *
     * @return string|null An object title/name
     */
    public function toString(): ?string
    {
        // TODO: Something more human-friendly?
        return $this->id . '@' . \config('app.domain');
    }
}
