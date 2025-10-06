<?php

namespace App;

use App\Traits\BelongsToUserTrait;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * The eloquent definition of a VerificationCode
 *
 * @property bool   $active     Active status
 * @property string $code       The code
 * @property Carbon $expires_at Expiration date-time
 * @property string $mode       Mode, e.g. password-reset
 * @property int    $user_id    User identifier
 * @property string $short_code Short code
 */
class VerificationCode extends Model
{
    use BelongsToUserTrait;

    // Code expires after so many hours
    public const SHORTCODE_LENGTH = 6;

    public const CODE_LENGTH = 32;

    // Code expires after so many hours
    public const CODE_EXP_HOURS = 8;

    public const MODE_EMAIL = 'ext-email';
    public const MODE_PASSWORD = 'password-reset';

    /** @var string The primary key associated with the table */
    protected $primaryKey = 'code';

    /** @var bool Indicates if the IDs are auto-incrementing */
    public $incrementing = false;

    /** @var string The "type" of the auto-incrementing ID */
    protected $keyType = 'string';

    /** @var bool Indicates if the model should be timestamped */
    public $timestamps = false;

    /** @var array<string, string> Casts properties as type */
    protected $casts = [
        'active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = ['user_id', 'code', 'short_code', 'mode', 'expires_at', 'active'];

    /**
     * Apply action on verified code.
     */
    protected function applyAction(&$message): bool
    {
        switch ($this->mode) {
            case self::MODE_EMAIL:
                $settings = $this->user->getSettings(['external_email_new', 'external_email_code']);

                if ($settings['external_email_code'] != $this->code) {
                    return false;
                }

                $this->user->setSettings([
                    'external_email' => $settings['external_email_new'],
                    'external_email_new' => null,
                    'external_email_code' => null,
                ]);

                $this->delete();
                $message = \trans('app.code-verified-email');
                break;
        }

        return true;
    }

    /**
     * Validate a code and execute defined action if valid.
     *
     * @param string  $short_code Short code
     * @param ?string $message    Success message
     */
    public function codeValidate(string $short_code, &$message = null): bool
    {
        if (!$this->active || $this->isExpired()) {
            return false;
        }

        if (\strtoupper($short_code) !== \strtoupper($this->short_code)) {
            return false;
        }

        return $this->applyAction($message);
    }

    /**
     * Generate a short (numeric) code (for human).
     */
    public static function generateShortCode(): string
    {
        $test_code = \config('app.test_verification_code');

        if (strlen($test_code)) {
            return $test_code;
        }

        $code_length = env('VERIFICATION_CODE_LENGTH', self::SHORTCODE_LENGTH);

        return Utils::randStr($code_length, 1, '', '1234567890');
    }

    /**
     * Check if code is expired.
     *
     * @return bool True if code is expired, False otherwise
     */
    public function isExpired()
    {
        return $this->expires_at ? Carbon::now()->gte($this->expires_at) : false;
    }
}
