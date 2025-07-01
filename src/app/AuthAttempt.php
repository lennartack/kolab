<?php

namespace App;

use App\Traits\UuidStrKeyTrait;
use Carbon\Carbon;
use Dyrynda\Database\Support\NullableFields;
use Illuminate\Database\Eloquent\Model;

/**
 * The eloquent definition of an AuthAttempt.
 *
 * An AuthAttempt represents an authenticaton attempt from an application/client.
 */
class AuthAttempt extends Model
{
    use NullableFields;
    use UuidStrKeyTrait;

    public const REASON_NONE = '';
    public const REASON_PASSWORD = 'password';
    public const REASON_PASSWORD_EXPIRED = 'password-expired';
    public const REASON_GEOLOCATION = 'geolocation';
    public const REASON_NOTFOUND = 'notfound';
    public const REASON_2FA = '2fa';
    public const REASON_2FA_GENERIC = '2fa-generic';

    private const STATUS_ACCEPTED = 'ACCEPTED';
    private const STATUS_DENIED = 'DENIED';

    /** @var list<string> The attributes that can be not set */
    protected $nullable = ['reason'];

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = [
        'ip',
        'user_id',
        'status',
        'reason',
        'expires_at',
        'last_seen',
    ];

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'expires_at' => 'datetime',
        'last_seen' => 'datetime',
    ];

    /**
     * Prepare a date for array / JSON serialization.
     *
     * Required to not omit timezone and match the format of update_at/created_at timestamps.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return Carbon::instance($date)->toIso8601ZuluString('microseconds');
    }

    /**
     * Returns true if the authentication attempt is accepted.
     */
    public function isAccepted(): bool
    {
        return $this->status == self::STATUS_ACCEPTED && Carbon::now() < $this->expires_at;
    }

    /**
     * Returns true if the authentication attempt is denied.
     */
    public function isDenied(): bool
    {
        return $this->status == self::STATUS_DENIED;
    }

    /**
     * Accept the authentication attempt.
     */
    public function accept($reason = self::REASON_NONE)
    {
        $this->expires_at = Carbon::now()->addHours(8);
        $this->status = self::STATUS_ACCEPTED;
        $this->reason = $reason;
        $this->save();
    }

    /**
     * Deny the authentication attempt.
     */
    public function deny($reason = self::REASON_NONE)
    {
        $this->status = self::STATUS_DENIED;
        $this->reason = $reason;
        $this->save();
    }

    /**
     * Notify the user of this authentication attempt.
     *
     * @return bool false if there was no means to notify
     */
    public function notify(): bool
    {
        return CompanionApp::notifyUser($this->user_id, ['token' => $this->id]);
    }

    /**
     * Notify the user and wait for a confirmation.
     */
    private function notifyAndWait()
    {
        if (!$this->notify()) {
            // FIXME if the webclient can confirm too we don't need to abort here.
            \Log::warning("There is no 2fa device to notify.");
            return false;
        }

        \Log::debug("Authentication attempt: {$this->id}");

        $confirmationTimeout = 120;
        $timeout = Carbon::now()->addSeconds($confirmationTimeout);

        while (true) {
            if ($this->isDenied()) {
                \Log::debug("The authentication attempt was denied {$this->id}");
                return false;
            }

            if ($this->isAccepted()) {
                \Log::debug("The authentication attempt was accepted {$this->id}");
                return true;
            }

            if ($timeout < Carbon::now()) {
                \Log::debug("The authentication attempt timed-out: {$this->id}");
                return false;
            }

            sleep(2);
            $this->refresh();
        }
    }

    /**
     * Record a new authentication attempt or update an existing one.
     *
     * @param User   $user     the user attempting to authenticate
     * @param string $clientIP the ip the authentication attempt is coming from
     *
     * @return AuthAttempt
     */
    public static function recordAuthAttempt(User $user, $clientIP)
    {
        $authAttempt = self::where('ip', $clientIP)->where('user_id', $user->id)->first();

        if (!$authAttempt) {
            $authAttempt = new self();
            $authAttempt->ip = $clientIP;
            $authAttempt->user_id = $user->id;
        }

        $authAttempt->last_seen = Carbon::now();
        $authAttempt->save();

        return $authAttempt;
    }

    /**
     * Trigger a notification if necessary and wait for confirmation.
     *
     * @return bool Returns true if the attempt is accepted on confirmation
     */
    public function waitFor2FA(): bool
    {
        if ($this->isAccepted()) {
            return true;
        }

        if ($this->isDenied()) {
            return false;
        }

        if (!$this->notifyAndWait()) {
            return false;
        }

        return $this->isAccepted();
    }
}
