<?php

namespace App\Traits;

use App\Entitlement;
use App\Package;
use App\Sku;
use App\Transaction;
use App\User;
use App\Wallet;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property ?User $account Account (wallet) owner
 */
trait EntitleableTrait
{
    /**
     * Get the account (wallet) owner. Mutated walletOwner() result.
     */
    protected function account(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->walletOwner(),
        );
    }

    /**
     * Assign a package to an entitleable object. It should not have any existing entitlements.
     *
     * @param Package $package The package
     * @param Wallet  $wallet  The wallet
     *
     * @return $this
     */
    public function assignPackageAndWallet(Package $package, Wallet $wallet)
    {
        // TODO: There should be some sanity checks here. E.g. not package can be
        // assigned to any entitleable, but we don't really have package types.

        foreach ($package->skus as $sku) {
            for ($i = $sku->pivot->qty; $i > 0; $i--) {
                Entitlement::create([
                    'wallet_id' => $wallet->id,
                    'sku_id' => $sku->id,
                    'cost' => $sku->pivot->cost(),
                    'fee' => $sku->pivot->fee(),
                    'entitleable_id' => $this->id,
                    'entitleable_type' => self::class,
                ]);
            }
        }

        return $this;
    }

    /**
     * Assign a SKU to an entitleable object.
     *
     * @param Sku     $sku    the sku to assign
     * @param int     $count  Count of entitlements to add
     * @param ?Wallet $wallet The wallet to use when objects's wallet is unknown
     *
     * @return $this
     *
     * @throws \Exception
     */
    public function assignSku(Sku $sku, int $count = 1, $wallet = null)
    {
        if (!$wallet) {
            $wallet = $this->wallet();
        }

        if (!$wallet) {
            throw new \Exception("No wallet specified for the new entitlement");
        }

        $exists = $this->entitlements()->where('sku_id', $sku->id)->count();

        while ($count > 0) {
            Entitlement::create([
                'wallet_id' => $wallet->id,
                'sku_id' => $sku->id,
                'cost' => $exists >= $sku->units_free ? $sku->cost : 0,
                'fee' => $exists >= $sku->units_free ? $sku->fee : 0,
                'entitleable_id' => $this->id,
                'entitleable_type' => self::class,
            ]);

            $exists++;
            $count--;
        }

        return $this;
    }

    /**
     * Assign the object to a wallet.
     *
     * @param Wallet  $wallet The wallet
     * @param ?string $title  Optional SKU title
     *
     * @return $this
     *
     * @throws \Exception
     */
    public function assignToWallet(Wallet $wallet, $title = null)
    {
        if (empty($this->id)) {
            throw new \Exception("Object not yet exists");
        }

        if ($this->entitlements()->count()) {
            throw new \Exception("Object already assigned to a wallet");
        }

        // Find the SKU title, e.g. \App\SharedFolder -> shared-folder
        // Note: it does not work with User/Domain model (yet)
        if (!$title) {
            $title = Str::kebab(\class_basename(self::class));
        }

        $sku = $this->skuByTitle($title);
        $exists = $wallet->entitlements()->where('sku_id', $sku->id)->count();

        Entitlement::create([
            'wallet_id' => $wallet->id,
            'sku_id' => $sku->id,
            'cost' => $exists >= $sku->units_free ? $sku->cost : 0,
            'fee' => $exists >= $sku->units_free ? $sku->fee : 0,
            'entitleable_id' => $this->id,
            'entitleable_type' => self::class,
        ]);

        return $this;
    }

    /**
     * Boot function from Laravel.
     */
    protected static function bootEntitleableTrait()
    {
        // Soft-delete and force-delete object's entitlements on object's delete
        static::deleting(static function ($model) {
            $force = $model->isForceDeleting();
            $entitlements = $model->entitlements();

            if ($force) {
                $entitlements = $entitlements->withTrashed();
            }

            $list = $entitlements->get()
                ->map(static function ($entitlement) use ($force) {
                    if ($force) {
                        $entitlement->forceDelete();
                    } else {
                        $entitlement->delete();
                    }
                    return $entitlement->id;
                })
                ->all();

            // Remove transactions, they have no foreign key constraint
            if ($force && !empty($list)) {
                Transaction::where('object_type', Entitlement::class)
                    ->whereIn('object_id', $list)
                    ->delete();
            }
        });

        // Restore object's entitlements on restore
        static::restored(static function ($model) {
            $model->restoreEntitlements();
        });
    }

    /**
     * Count entitlements for the specified SKU.
     *
     * @param string $title The SKU title
     *
     * @return int Numer of entitlements
     */
    public function countEntitlementsBySku(string $title): int
    {
        $sku = $this->skuByTitle($title);

        if (!$sku) {
            return 0;
        }

        return $this->entitlements()->where('sku_id', $sku->id)->count();
    }

    /**
     * Entitlements for this object.
     *
     * @return HasMany<Entitlement, $this>
     */
    public function entitlements()
    {
        return $this->hasMany(Entitlement::class, 'entitleable_id', 'id')
            ->where('entitleable_type', self::class);
    }

    /**
     * Check if an entitlement for the specified SKU exists.
     *
     * @param string $title The SKU title
     *
     * @return bool True if specified SKU entitlement exists
     */
    public function hasSku(string $title): bool
    {
        return $this->countEntitlementsBySku($title) > 0;
    }

    /**
     * Remove a number of entitlements for the SKU.
     *
     * @param Sku $sku   The SKU
     * @param int $count The number of entitlements to remove
     *
     * @return $this
     */
    public function removeSku(Sku $sku, int $count = 1)
    {
        $entitlements = $this->entitlements()
            ->where('sku_id', $sku->id)
            ->orderBy('cost', 'desc')
            ->orderBy('created_at')
            ->get();

        $entitlements_count = count($entitlements);

        foreach ($entitlements as $entitlement) {
            if ($entitlements_count <= $sku->units_free) {
                continue;
            }

            if ($count > 0) {
                $entitlement->delete();
                $entitlements_count--;
                $count--;
            }
        }

        return $this;
    }

    /**
     * Restore object entitlements.
     */
    public function restoreEntitlements(): void
    {
        // We'll restore only these that were deleted last. So, first we get
        // the maximum deleted_at timestamp and then use it to select
        // entitlements for restore
        $deleted_at = $this->entitlements()->withTrashed()->max('deleted_at');

        if ($deleted_at) {
            $threshold = (new Carbon($deleted_at))->subMinute();

            // Restore object entitlements
            $this->entitlements()->withTrashed()
                ->where('deleted_at', '>=', $threshold)
                ->update(['updated_at' => now(), 'deleted_at' => null]);

            // Note: We're assuming that cost of entitlements was correct
            // on deletion, so we don't have to re-calculate it again.
            // TODO: We should probably re-calculate the cost
        }
    }

    /**
     * Find the SKU object by title. Use current object's tenant context.
     *
     * @param string $title SKU title
     *
     * @return ?Sku A SKU object
     */
    protected function skuByTitle(string $title): ?Sku
    {
        return Sku::withObjectTenantContext($this)->where('title', $title)->first();
    }

    /**
     * Get all SKU titles for this object.
     *
     * @return array<string>
     */
    public function skuTitles(): array
    {
        return $this->entitlements()->distinct()
            ->join('skus', 'skus.id', '=', 'entitlements.sku_id')
            ->pluck('title')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Returns entitleable object title (e.g. email or domain name).
     *
     * @return string|null An object title/name
     */
    public function toString(): ?string
    {
        // This method should be overloaded by the model class
        // if the object has not email attribute
        return $this->email;
    }

    /**
     * Returns the wallet by which the object is controlled
     *
     * @return ?Wallet A wallet object
     */
    public function wallet(): ?Wallet
    {
        $entitlement = $this->entitlements()->withTrashed()->orderBy('created_at', 'desc')->first();

        if ($entitlement) {
            return $entitlement->wallet;
        }

        // TODO: No entitlement should not happen, but in tests we have
        //       such cases, so we fallback to the user's wallet in this case
        if ($this instanceof User) {
            return $this->wallets()->first();
        }

        return null;
    }

    /**
     * Return the owner of the wallet (account) this entitleable is assigned to
     *
     * @return ?User Account owner
     */
    public function walletOwner(): ?User
    {
        $wallet = $this->wallet();

        if ($wallet) {
            if ($this instanceof User && $wallet->user_id == $this->id) {
                return $this;
            }

            return $wallet->owner;
        }

        return null;
    }
}
