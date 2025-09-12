<?php

namespace App;

use App\Auth\PassportClient;
use App\Traits\UuidStrKeyTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Client;

/**
 * The eloquent definition of a CompanionApp.
 *
 * A CompanionApp is an kolab companion app that the user registered
 *
 * @property string  $device_id
 * @property string  $id                 App identifier
 * @property string  $name               App name
 * @property ?string $notification_token Firebase notification token
 * @property bool    $mfa_enabled
 * @property string  $oauth_client_id    Oauth client identifier
 * @property int     $user_id            User (owner) identifier
 */
class CompanionApp extends Model
{
    use UuidStrKeyTrait;

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = [
        'name',
        'user_id',
        'device_id',
        'notification_token',
        'mfa_enabled',
    ];

    /**
     * Send a notification via firebase.
     *
     * @param array $deviceIds A list of device id's to send the notification to
     * @param array $data      the data to include in the notification
     *
     * @return bool true if a notification has been sent
     *
     * @throws \Exception on notification failure
     */
    private static function pushFirebaseNotification($deviceIds, $data): bool
    {
        \Log::debug("sending notification to " . var_export($deviceIds, true));

        $apiKey = \config('services.firebase.api_key');
        $apiUrl = \config('services.firebase.api_url');
        $verify = \config('services.firebase.api_verify_tls');

        if (empty($apiKey)) {
            return false;
        }

        Http::withOptions(['verify' => $verify])
            ->withHeaders(['Authorization' => "key={$apiKey}"])
            ->timeout(5)
            ->connectTimeout(5)
            ->post($apiUrl, ['registration_ids' => $deviceIds, 'data' => $data])
            ->throwUnlessStatus(200);

        return true;
    }

    /**
     * Send a notification to a user.
     *
     * @return bool true if a notification has been sent
     *
     * @throws \Exception on notification failure
     */
    public static function notifyUser($userId, $data): bool
    {
        $notificationTokens = self::where('user_id', $userId)
            ->where('mfa_enabled', true)
            ->pluck('notification_token')
            ->all();

        if (empty($notificationTokens)) {
            \Log::debug("There is no 2fa device to notify.");
            return false;
        }

        return self::pushFirebaseNotification($notificationTokens, $data);
    }

    /**
     * Returns whether this companion app is paired with a device.
     */
    public function isPaired(): bool
    {
        return !empty($this->device_id);
    }

    /**
     * The PassportClient of this CompanionApp
     *
     * @return PassportClient|null
     */
    public function passportClient()
    {
        return PassportClient::find($this->oauth_client_id);
    }

    /**
     * Set the PassportClient of this CompanionApp
     *
     * @param Client $client The client object
     */
    public function setPassportClient(Client $client)
    {
        return $this->oauth_client_id = $client->id;
    }
}
