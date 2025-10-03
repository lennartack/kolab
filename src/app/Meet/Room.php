<?php

namespace App\Meet;

use App\Permission;
use App\Traits\BelongsToTenantTrait;
use App\Traits\EntitleableTrait;
use App\Traits\Meet\RoomConfigTrait;
use App\Traits\PermissibleTrait;
use App\Traits\SettingsTrait;
use Dyrynda\Database\Support\NullableFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The eloquent definition of a Room.
 *
 * @property int     $id          Room identifier
 * @property ?string $description Description
 * @property string  $name        Room name
 * @property int     $tenant_id   Tenant identifier
 * @property ?string $session_id  Meet session identifier
 */
class Room extends Model
{
    use BelongsToTenantTrait;
    use EntitleableTrait;
    use NullableFields;
    use PermissibleTrait;
    use RoomConfigTrait;
    use SettingsTrait;
    use SoftDeletes;

    public const ROLE_SUBSCRIBER = 1 << 0;
    public const ROLE_PUBLISHER = 1 << 1;
    public const ROLE_MODERATOR = 1 << 2;
    public const ROLE_SCREEN = 1 << 3;
    public const ROLE_OWNER = 1 << 4;

    public const REQUEST_ACCEPTED = 'accepted';
    public const REQUEST_DENIED = 'denied';

    /** @var array<string, string> The attributes that should be cast */
    protected $casts = [
        'created_at' => 'datetime:Y-m-d H:i:s',
        'deleted_at' => 'datetime:Y-m-d H:i:s',
        'updated_at' => 'datetime:Y-m-d H:i:s',
    ];

    /** @var list<string> The attributes that are mass assignable */
    protected $fillable = ['name', 'description'];

    /** @var array<int, string> The attributes that can be not set */
    protected $nullable = ['description'];

    /** @var string Database table name */
    protected $table = 'openvidu_rooms';

    /**
     * Creates HTTP client for connections to Meet server
     *
     * @return Http client instance
     */
    private function client()
    {
        return Service::clientForRoom($this->name);
    }

    /**
     * Create a Meet session
     *
     * @return array|null Session data on success, NULL otherwise
     */
    public function createSession(): ?array
    {
        $response = $this->client()->post('sessions');

        if ($response->status() !== 200) {
            $this->logError("Failed to create the meet session", $response);
            $this->session_id = null;
            $this->save();
            return null;
        }

        $session = $response->json();

        $this->session_id = $session['id'];
        $this->save();

        return $session;
    }

    /**
     * Create a Meet session (connection) token
     *
     * @param int $role User role (see self::ROLE_* constants)
     *
     * @return string|null Session token on success, NULL otherwise
     *
     * @throws \Exception if session does not exist
     */
    public function getSessionToken($role = self::ROLE_SUBSCRIBER): ?string
    {
        if (!$this->session_id) {
            throw new \Exception("The room session does not exist");
        }

        $url = 'sessions/' . $this->session_id . '/connection';
        $post = [
            'role' => $role,
        ];

        $response = $this->client()->post($url, $post);

        if ($response->status() == 200) {
            return $response->json('token');
        }

        $this->logError("Failed to create the meet peer connection", $response);

        return null;
    }

    /**
     * Check if the room has an active session
     *
     * @return bool True when the session exists, False otherwise
     */
    public function hasSession(): bool
    {
        if (!$this->session_id) {
            return false;
        }

        $response = $this->client()->get("sessions/{$this->session_id}");

        $this->logError("Failed to check that a meet session exists", $response);

        return $response->status() == 200;
    }

    /**
     * Accept the join request.
     *
     * @param string $id Request identifier
     *
     * @return bool True on success, False on failure
     */
    public function requestAccept(string $id): bool
    {
        $request = Cache::get($this->session_id . '-' . $id);

        if ($request) {
            $request['status'] = self::REQUEST_ACCEPTED;

            return Cache::put($this->session_id . '-' . $id, $request, now()->addHours(1));
        }

        return false;
    }

    /**
     * Deny the join request.
     *
     * @param string $id Request identifier
     *
     * @return bool True on success, False on failure
     */
    public function requestDeny(string $id): bool
    {
        $request = Cache::get($this->session_id . '-' . $id);

        if ($request) {
            $request['status'] = self::REQUEST_DENIED;

            return Cache::put($this->session_id . '-' . $id, $request, now()->addHours(1));
        }

        return false;
    }

    /**
     * Get the join request data.
     *
     * @param string $id Request identifier
     *
     * @return array|null Request data (e.g. nickname, status, picture?)
     */
    public function requestGet(string $id): ?array
    {
        return Cache::get($this->session_id . '-' . $id);
    }

    /**
     * Save the join request.
     *
     * @param string $id      Request identifier
     * @param array  $request Request data
     *
     * @return bool True on success, False on failure
     */
    public function requestSave(string $id, array $request): bool
    {
        // We don't really need the picture in the cache
        // As we use this cache for the request status only
        unset($request['picture']);

        return Cache::put($this->session_id . '-' . $id, $request, now()->addHours(1));
    }

    /**
     * Send a signal to the Meet session participants (peers)
     *
     * @param string $name   Signal name (type)
     * @param array  $data   Signal data array
     * @param int    $target Limit targets by their participant role
     *
     * @return bool True on success, False on failure
     *
     * @throws \Exception if session does not exist
     */
    public function signal(string $name, array $data = [], $target = null): bool
    {
        if (!$this->session_id) {
            throw new \Exception("The room session does not exist");
        }

        $post = [
            'roomId' => $this->session_id,
            'type' => $name,
            'role' => $target,
            'data' => $data,
        ];

        $response = $this->client()->post('signal', $post);

        $this->logError("Failed to send a signal to the meet session", $response);

        return $response->status() == 200;
    }

    /**
     * Returns a map of supported ACL labels.
     *
     * @return array Map of supported permission rights/ACL labels
     */
    protected function supportedACL(): array
    {
        return [
            'full' => Permission::READ | Permission::WRITE | Permission::ADMIN,
        ];
    }

    /**
     * Returns room name (required by the EntitleableTrait).
     *
     * @return string|null Room name
     */
    public function toString(): ?string
    {
        return $this->name;
    }

    /**
     * Log an error for a failed request to the meet server
     *
     * @param string $str      The error string
     * @param object $response Guzzle client response
     */
    private function logError(string $str, $response)
    {
        $code = $response->status();
        if ($code != 200) {
            \Log::error("{$str} [{$code}]");
        }
    }
}
