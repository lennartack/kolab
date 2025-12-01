<?php

namespace App\Fs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The eloquent definition of a filesystem relation.
 *
 * @property string $id         Relation identifier
 * @property string $item_id    Item identifier
 * @property string $related_id Related item identifier
 */
class Relation extends Model
{
    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = ['item_id', 'related_id'];

    /** @var string Database table name */
    protected $table = 'fs_relations';

    /** @var bool Indicates if the model should be timestamped. */
    public $timestamps = false;

    /**
     * The item to which this relation belongs.
     *
     * @return BelongsTo<Item, $this>
     */
    public function item()
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * The item to which it relates.
     *
     * @return BelongsTo<Item, $this>
     */
    public function related()
    {
        return $this->belongsTo(Item::class, 'related_id');
    }
}
