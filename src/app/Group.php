<?php

namespace App;

use App\Traits\BelongsToTenantTrait;
use App\Traits\EmailPropertyTrait;
use App\Traits\EntitleableTrait;
use App\Traits\GroupConfigTrait;
use App\Traits\SettingsTrait;
use App\Traits\StatusPropertyTrait;
use App\Traits\UuidIntKeyTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The eloquent definition of a Group.
 *
 * @property int    $id        The group identifier
 * @property string $email     An email address
 * @property string $name      The group name
 * @property int    $status    The group status
 * @property int    $tenant_id Tenant identifier
 */
class Group extends Model
{
    use BelongsToTenantTrait;
    use EntitleableTrait;
    use GroupConfigTrait;
    use SettingsTrait;
    use SoftDeletes;
    use StatusPropertyTrait;
    use UuidIntKeyTrait;
    use EmailPropertyTrait; // must be after UuidIntKeyTrait

    // we've simply never heard of this group
    public const STATUS_NEW = 1 << 0;
    // group has been activated
    public const STATUS_ACTIVE = 1 << 1;
    // group has been suspended.
    public const STATUS_SUSPENDED = 1 << 2;
    // group has been deleted
    public const STATUS_DELETED = 1 << 3;
    // group has been created in LDAP
    public const STATUS_LDAP_READY = 1 << 4;

    /** @var int The allowed states for this object used in StatusPropertyTrait */
    private int $allowed_states = self::STATUS_NEW
        | self::STATUS_ACTIVE
        | self::STATUS_SUSPENDED
        | self::STATUS_DELETED
        | self::STATUS_LDAP_READY;

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = [
        'email',
        'name',
        'status',
    ];

    /**
     * Returns list of group member email addresses.
     */
    public function getAddresses(): array
    {
        return $this->members()->orderBy('email')->pluck('email')->all();
    }

    /**
     * Replace members with a new list of members.
     *
     * @param array $members Email addresses of the group members
     */
    public function setAddresses(array $members, bool $silently = false): void
    {
        $members = array_unique(array_filter(array_map('strtolower', $members)));

        $existing = $this->getAddresses();
        $added = array_diff($members, $existing);
        $removed = array_diff($existing, $members);

        if (count($removed)) {
            $this->members()->whereIn('email', $removed)->delete();
        }

        if (count($added)) {
            $this->members()->createMany(array_map(fn ($member) => ['email' => $member], $added));
        }

        if (!$silently && !$this->trashed() && (count($removed) || count($added))) {
            // Trigger an update job on the group, as we do not observe members
            \App\Jobs\Group\UpdateJob::dispatch($this->id);
        }
    }

    /**
     * The relationship to members.
     *
     * @return HasMany<GroupMember, $this>
     */
    public function members()
    {
        return $this->hasMany(GroupMember::class);
    }
}
