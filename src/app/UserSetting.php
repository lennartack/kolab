<?php

namespace App;

use App\Traits\BelongsToUserTrait;
use Illuminate\Database\Eloquent\Model;

/**
 * A collection of settings for a User.
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $key
 * @property string $value
 */
class UserSetting extends Model
{
    use BelongsToUserTrait;

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = ['user_id', 'key', 'value'];

    /**
     * Check if the setting is used in any storage backend.
     */
    public function isBackendSetting(): bool
    {
        return \config('app.with_ldap')
            && in_array($this->key, ['first_name', 'last_name', 'organization']);
    }
}
