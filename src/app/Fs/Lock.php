<?php

namespace App\Fs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sabre\DAV\Locks\LockInfo;

/**
 * The eloquent definition of a filesystem lock.
 *
 * @property int    $depth   Lock depth (0 or -1)
 * @property int    $id      Lock identifier
 * @property string $item_id Item identifier
 * @property string $owner   Lock owner information
 * @property int    $scope   Lock scope (1 or 2)
 * @property int    $timeout Lock timeout (in seconds, or -1 for infinite)
 * @property string $token   Lock token
 */
class Lock extends Model
{
    public const DEPTH_INFINITY = \Sabre\DAV\Server::DEPTH_INFINITY;
    public const SCOPE_SHARED = LockInfo::SHARED;
    public const SCOPE_EXCLUSIVE = LockInfo::EXCLUSIVE;
    public const TIMEOUT_INFINITE = LockInfo::TIMEOUT_INFINITE;

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'depth' => 'integer',
        'scope' => 'integer',
        'timeout' => 'integer',
    ];

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = ['depth', 'item_id', 'token', 'scope', 'timeout', 'owner'];

    /** @var string Database table name */
    protected $table = 'fs_locks';

    /** @var bool Indicates if the model should be timestamped. */
    public $timestamps = false;

    /**
     * The filesystem item the lock is on.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
