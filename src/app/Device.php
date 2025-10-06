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
            throw new \Exception("A plan with a device SKU is required");
        }

        foreach ($device_packages as $package) {
            $this->assignPackageAndWallet($package, $wallet);
        }

        // Push entitlements.updated_at to one year in the future
        $threshold = (clone $this->created_at)->addYearWithoutOverflow();
        if ($threshold > \now()) {
            $this->entitlements()->each(static function ($entitlement) use ($threshold) {
                $entitlement->updated_at = $threshold;
                $entitlement->save();
            });
        }
    }

    /**
     * Assign device to (another) real user account
     *
     * @param string $token Device token
     * @param User   $user  User claiming the device ownership
     */
    public static function claim(string $token, User $user): self
    {
        $plan = null;

        // Existing user's plan
        if ($plan_id = $user->getSetting('plan_id')) {
            $plan = Plan::withObjectTenantContext($user)->find($plan_id);
        }

        // TODO: Get "default" device package and assign if none found above, or just use the device SKU?

        if (!$plan || !$plan->hasSku(self::class)) {
            throw new \Exception("A plan with a device SKU is required");
        }

        DB::beginTransaction();

        $device = self::initDevice($token);
        $device->assignPlan($plan, $user->wallets()->first());

        DB::commit();

        return $device;
    }

    /**
     * Get a default plan for a device signup
     *
     * @param string|SignupToken $token    Device token
     * @param bool               $isDevice Get plan for a device, rather than a user
     */
    public static function defaultPlan($token, bool $isDevice): ?Plan
    {
        if (is_string($token)) {
            $token = SignupToken::find(strtoupper($token));
        }

        if (empty($token)) {
            return null;
        }

        $plans = Plan::withEnvTenantContext()
            ->where('mode', Plan::MODE_TOKEN)
            ->whereIn('id', $token->plans)
            // TODO: Instead of using a prefix match, we should check the plan SKUs
            ->whereLike('title', $isDevice ? 'device-%' : 'user-%')
            ->get()
            ->keyBy('title');

        if (!$plans->count()) {
            return null;
        }

        $defaults = $plans->filter(function ($plan, $key) {
            return str_contains($key, 'default');
        });

        if ($defaults->count()) {
            return $defaults->first();
        }

        return $plans->first();
    }

    /**
     * Setup a device record for the token. Create one if needed.
     * Warning: It also removes any existing association with a user.
     *
     * @param string $token Device token
     */
    public static function initDevice(string $token): self
    {
        // Check if a device already exists
        $device = self::withTrashed()->where('hash', $token)->first();

        if ($device) {
            // FIXME: Should we remove the user (if it's a role=device user)?

            // Remove the existing device-to-wallet connection
            $device->entitlements()->each(function ($entitlement) {
                $entitlement->delete();
            });

            // Undelete the device if needed
            if ($device->trashed()) {
                $device->withoutEvents(function () use ($device) {
                    $device->restore();
                });
            }
        } else {
            // Create a device
            $device = self::create(['hash' => $token]);
        }

        return $device;
    }

    /**
     * Signup a device
     */
    public static function signup(string $token, Plan $plan, string $password): self
    {
        DB::beginTransaction();

        $device = self::initDevice($token);

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
        $device->assignPlan($plan, $user->wallets()->first());

        // FIXME: Should this bump signup_tokens.counter, as it does for user signup?

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
