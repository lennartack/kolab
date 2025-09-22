<?php

namespace App;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * The eloquent definition of a SignupToken.
 *
 * @property Carbon  $created_at The creation timestamp
 * @property int     $counter    Count of signups on this token
 * @property ?string $id         Token
 * @property array   $plans      Plan identifiers
 */
class SignupToken extends Model
{
    /** @var bool Indicates if the IDs are auto-incrementing */
    public $incrementing = false;

    /** @var string The "type" of the auto-incrementing ID */
    protected $keyType = 'string';

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = [
        'plans',
        'id',
        'counter',
    ];

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'counter' => 'integer',
        'plans' => 'array',
    ];

    /** @var bool Indicates if the model should be timestamped. */
    public $timestamps = false;
}
