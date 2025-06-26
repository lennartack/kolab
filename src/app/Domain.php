<?php

namespace App;

use App\Traits\BelongsToTenantTrait;
use App\Traits\DomainConfigTrait;
use App\Traits\EntitleableTrait;
use App\Traits\SettingsTrait;
use App\Traits\StatusPropertyTrait;
use App\Traits\UuidIntKeyTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The eloquent definition of a Domain.
 *
 * @property string $namespace
 * @property int    $status
 * @property int    $tenant_id
 * @property int    $type
 */
class Domain extends Model
{
    use BelongsToTenantTrait;
    use DomainConfigTrait;
    use EntitleableTrait;
    use SettingsTrait;
    use SoftDeletes;
    use StatusPropertyTrait;
    use UuidIntKeyTrait;

    // we've simply never heard of this domain
    public const STATUS_NEW = 1 << 0;
    // it's been activated
    public const STATUS_ACTIVE = 1 << 1;
    // domain has been suspended.
    public const STATUS_SUSPENDED = 1 << 2;
    // domain has been deleted
    public const STATUS_DELETED = 1 << 3;
    // ownership of the domain has been confirmed
    public const STATUS_CONFIRMED = 1 << 4;
    // domain has been verified that it exists in DNS
    public const STATUS_VERIFIED = 1 << 5;
    // domain has been created in LDAP
    public const STATUS_LDAP_READY = 1 << 6;

    // open for public registration
    public const TYPE_PUBLIC = 1 << 0;
    // zone hosted with us
    public const TYPE_HOSTED = 1 << 1;
    // zone registered externally
    public const TYPE_EXTERNAL = 1 << 2;

    public const HASH_CODE = 1;
    public const HASH_TEXT = 2;
    public const HASH_CNAME = 3;

    /** @var int The allowed states for this object used in StatusPropertyTrait */
    private int $allowed_states = self::STATUS_NEW
        | self::STATUS_ACTIVE
        | self::STATUS_SUSPENDED
        | self::STATUS_DELETED
        | self::STATUS_CONFIRMED
        | self::STATUS_VERIFIED
        | self::STATUS_LDAP_READY;

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'status' => 'integer',
        'type' => 'integer',
    ];

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = ['namespace', 'status', 'type'];

    /**
     * Assign a package to a domain. The domain should not belong to any existing entitlements.
     *
     * @param Package $package the package to assign
     * @param User    $user    the wallet owner
     *
     * @return Domain Self
     */
    public function assignPackage($package, $user)
    {
        // If this domain is public it can not be assigned to a user.
        if ($this->isPublic()) {
            return $this;
        }

        // See if this domain is already owned by another user.
        $wallet = $this->wallet();

        if ($wallet) {
            throw new \Exception("Domain {$this->namespace} is already assigned to {$wallet->owner->email}");
        }

        return $this->assignPackageAndWallet($package, $user->wallets()->first());
    }

    /**
     * Return list of public+active domain names (for current tenant)
     */
    public static function getPublicDomains(): array
    {
        return self::withEnvTenantContext()
            ->where('type', '&', self::TYPE_PUBLIC)
            ->pluck('namespace')->all();
    }

    /**
     * Returns whether this domain is confirmed the ownership of.
     */
    public function isConfirmed(): bool
    {
        return ($this->status & self::STATUS_CONFIRMED) > 0;
    }

    /**
     * Returns whether this domain is registered with us.
     */
    public function isExternal(): bool
    {
        return ($this->type & self::TYPE_EXTERNAL) > 0;
    }

    /**
     * Returns whether this domain is hosted with us.
     */
    public function isHosted(): bool
    {
        return ($this->type & self::TYPE_HOSTED) > 0;
    }

    /**
     * Returns whether this domain is public.
     */
    public function isPublic(): bool
    {
        return ($this->type & self::TYPE_PUBLIC) > 0;
    }

    /**
     * Returns whether this (external) domain has been verified
     * to exist in DNS.
     */
    public function isVerified(): bool
    {
        return ($this->status & self::STATUS_VERIFIED) > 0;
    }

    /**
     * Ensure the namespace is appropriately cased.
     */
    public function setNamespaceAttribute($namespace)
    {
        $this->attributes['namespace'] = strtolower($namespace);
    }

    /**
     * Domain status mutator
     *
     * @throws \Exception
     */
    public function setStatusAttribute($status)
    {
        // Detect invalid flags
        if ($status & ~$this->allowed_states) {
            throw new \Exception("Invalid domain status: {$status}");
        }

        $new_status = $status;

        if ($this->isPublic()) {
            $this->attributes['status'] = $new_status;
            return;
        }

        // if we have confirmed ownership of or management access to the domain, then we have
        // also confirmed the domain exists in DNS.
        if ($new_status & self::STATUS_CONFIRMED) {
            $new_status |= self::STATUS_VERIFIED | self::STATUS_ACTIVE;
        }

        // it can't be deleted-or-suspended and active
        if ($new_status & self::STATUS_DELETED || $new_status & self::STATUS_SUSPENDED) {
            $new_status &= ~self::STATUS_ACTIVE;
        }

        // if the domain is now active, it is not new anymore.
        if ($new_status & self::STATUS_ACTIVE) {
            $new_status &= ~self::STATUS_NEW;
        }

        $this->attributes['status'] = $new_status;
    }

    /**
     * Ownership verification by checking for a TXT (or CNAME) record
     * in the domain's DNS (that matches the verification hash).
     *
     * @return bool True if verification was successful, false otherwise
     *
     * @throws \Exception Throws exception on DNS or DB errors
     */
    public function confirm(): bool
    {
        if ($this->isConfirmed()) {
            return true;
        }

        $hash = $this->hash(self::HASH_TEXT);
        $confirmed = false;

        // Get DNS records and find a matching TXT entry
        $records = \dns_get_record($this->namespace, \DNS_TXT);

        if ($records === false) {
            throw new \Exception("Failed to get DNS record for {$this->namespace}");
        }

        foreach ($records as $record) {
            if ($record['txt'] === $hash) {
                $confirmed = true;
                break;
            }
        }

        // Get DNS records and find a matching CNAME entry
        // Note: some servers resolve every non-existing name
        // so we need to define left and right side of the CNAME record
        // i.e.: kolab-verify IN CNAME <hash>.domain.tld.
        if (!$confirmed) {
            $cname = $this->hash(self::HASH_CODE) . '.' . $this->namespace;
            $records = \dns_get_record('kolab-verify.' . $this->namespace, \DNS_CNAME);

            if ($records === false) {
                throw new \Exception("Failed to get DNS record for {$this->namespace}");
            }

            foreach ($records as $records) {
                if ($records['target'] === $cname) {
                    $confirmed = true;
                    break;
                }
            }
        }

        if ($confirmed) {
            $this->status |= self::STATUS_CONFIRMED;
            $this->save();
        }

        return $confirmed;
    }

    /**
     * Generate a verification hash for this domain
     *
     * @param int $mod One of: HASH_CNAME, HASH_CODE (Default), HASH_TEXT
     *
     * @return string Verification hash
     */
    public function hash($mod = null): string
    {
        $cname = 'kolab-verify';

        if ($mod === self::HASH_CNAME) {
            return $cname;
        }

        $hash = \md5('hkccp-verify-' . $this->namespace);

        return $mod === self::HASH_TEXT ? "{$cname}={$hash}" : $hash;
    }

    /**
     * Checks if there are any objects (users/aliases/groups) in a domain.
     * Note: Public domains are always reported not empty.
     *
     * @return bool True if there are no objects assigned, False otherwise
     */
    public function isEmpty(): bool
    {
        if ($this->isPublic()) {
            return false;
        }

        // FIXME: These queries will not use indexes, so maybe we should consider
        // wallet/entitlements to search in objects that belong to this domain account?

        $suffix = '@' . $this->namespace;
        $suffixLen = strlen($suffix);

        return !(
            User::whereRaw('substr(email, ?) = ?', [-$suffixLen, $suffix])->exists()
            || UserAlias::whereRaw('substr(alias, ?) = ?', [-$suffixLen, $suffix])->exists()
            || Group::whereRaw('substr(email, ?) = ?', [-$suffixLen, $suffix])->exists()
            || Resource::whereRaw('substr(email, ?) = ?', [-$suffixLen, $suffix])->exists()
            || SharedFolder::whereRaw('substr(email, ?) = ?', [-$suffixLen, $suffix])->exists()
        );
    }

    /**
     * Returns domain's namespace (required by the EntitleableTrait).
     *
     * @return string|null Domain namespace
     */
    public function toString(): ?string
    {
        return $this->namespace;
    }

    /**
     * List the users of a domain, so long as the domain is not a public registration domain.
     *
     * @return Collection<User> A collection of users
     */
    public function users()
    {
        if ($this->isPublic()) {
            return collect([]);
        }

        $wallet = $this->wallet();

        if (!$wallet) {
            return collect([]);
        }

        return User::whereIn('id', static function ($query) use ($wallet) {
            $query->from('entitlements')
                ->select('entitleable_id')
                ->where('wallet_id', $wallet->id)
                ->where('entitleable_type', User::class)
                ->whereNull('deleted_at');
        })
            ->get();
    }

    /**
     * Verify if a domain exists in DNS
     *
     * @return bool True if registered, False otherwise
     *
     * @throws \Exception Throws exception on DNS or DB errors
     */
    public function verify(): bool
    {
        if ($this->isVerified()) {
            return true;
        }

        $records = \dns_get_record($this->namespace, \DNS_ANY);

        if ($records === false) {
            throw new \Exception("Failed to get DNS record for {$this->namespace}");
        }

        // It may happen that result contains other domains depending on the host DNS setup
        // that's why in_array() and not just !empty()
        if (in_array($this->namespace, array_column($records, 'host'))) {
            $this->status |= self::STATUS_VERIFIED;
            $this->save();

            return true;
        }

        return false;
    }
}
