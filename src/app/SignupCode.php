<?php

namespace App;

use App\Traits\BelongsToTenantTrait;
use App\Traits\BelongsToUserTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The eloquent definition of a SignupCode.
 *
 * @property string  $code              The full code identifier
 * @property Carbon  $created_at        The creation timestamp
 * @property Carbon  $deleted_at        The deletion timestamp
 * @property ?string $domain_part       Email domain
 * @property ?string $email             Email address
 * @property Carbon  $expires_at        The code expiration timestamp
 * @property ?string $first_name        Firstname
 * @property string  $ip_address        IP address the request came from
 * @property ?string $last_name         Lastname
 * @property ?string $local_part        Email local part
 * @property ?string $plan              Plan title
 * @property ?string $referral          Referral code
 * @property string  $short_code        Short validation code
 * @property Carbon  $updated_at        The update timestamp
 * @property string  $submit_ip_address IP address the final signup submit request came from
 * @property ?int    $tenant_id         Tenant identifier
 * @property string  $verify_ip_address IP address the code verify request came from
 * @property ?string $voucher           Voucher discount code
 */
class SignupCode extends Model
{
    use BelongsToTenantTrait;
    use BelongsToUserTrait;
    use SoftDeletes;

    public const SHORTCODE_LENGTH = 5;
    public const CODE_LENGTH = 32;

    // Code expires after so many hours
    public const CODE_EXP_HOURS = 24;

    /** @var string The primary key associated with the table */
    protected $primaryKey = 'code';

    /** @var bool Indicates if the IDs are auto-incrementing */
    public $incrementing = false;

    /** @var string The "type" of the auto-incrementing ID */
    protected $keyType = 'string';

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = [
        'code',
        'email',
        'expires_at',
        'first_name',
        'last_name',
        'plan',
        'referral',
        'short_code',
        'voucher',
    ];

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
        'expires_at' => 'datetime:Y-m-d H:i:s',
        'headers' => 'array',
    ];

    /**
     * Check if code is expired.
     *
     * @return bool True if code is expired, False otherwise
     */
    public function isExpired()
    {
        return $this->expires_at ? Carbon::now()->gte($this->expires_at) : false;
    }

    /**
     * Generate a short code (for human).
     */
    public static function generateShortCode(): string
    {
        $test_code = \config('app.test_verification_code');

        if (strlen($test_code)) {
            return $test_code;
        }

        $code_length = env('SIGNUP_CODE_LENGTH', self::SHORTCODE_LENGTH);

        return Utils::randStr($code_length);
    }
}
