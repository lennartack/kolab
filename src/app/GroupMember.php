<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A collection of group members.
 *
 * @property int    $id
 * @property int    $group_id
 * @property string $email
 */
class GroupMember extends Model
{
    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = ['group_id', 'email'];

    /** @var bool Indicates if the model should be timestamped. */
    public $timestamps = false;

    /**
     * The group to which this member belongs.
     *
     * @return BelongsTo<Group, $this>
     */
    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id', 'id');
    }

    /**
     * Ensure the email address is appropriately cased.
     *
     * @param string $email Email address
     */
    public function setEmailAttribute(string $email)
    {
        $this->attributes['email'] = \strtolower($email);
    }
}
