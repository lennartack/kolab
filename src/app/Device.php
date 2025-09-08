<?php

namespace App;

use App\Http\Resources\PlanResource;
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
     * Assign device to (another) real user account
     */
    public function bindTo(User $user)
    {
        $wallet = $user->wallets()->first();

        // TODO: What if the device is already used by another (real) user?

        $this->entitlements()->update(['wallet_id' => $wallet->id]);

        // TODO: Update created_at/updated_at accordingly
        // TODO: Delete the dummy user record?
    }

    /**
     * Get device information
     */
    public function info(): array
    {
        $plans = Plan::withObjectTenantContext($this)->where('mode', 'token')
            ->orderByDesc('months')->orderByDesc('title')
            ->get();

        $result = [
            // Device registration date-time
            'created_at' => (string) $this->created_at,
            // Plans available for signup via a device token
            'plans' => PlanResource::collection($plans),
        ];

        // TODO: Include other information about the plan/wallet/payments state

        return $result;
    }

    /**
     * Register a device
     */
    public static function register(string $hash): self
    {
        DB::beginTransaction();

        // Create a device record
        $device = self::create(['hash' => $hash]);

        // Create a special account (and wallet)
        $user = User::create([
            'email' => $device->id . '@' . \config('app.domain'),
            'password' => '',
            'role' => User::ROLE_DEVICE,
        ]);

        // Assign an entitlement
        $device->assignToWallet($user->wallets()->first(), 'device');

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
