<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Authentication response
 */
class AuthResource extends ApiResource
{
    public string $status = 'success';
    public ?string $message = null;
    public ?int $user_id = null;
    public ?array $checkout = null;
    public ?array $credentials = null;
    public ?DeviceInfoResource $device = null;

    private ?UserInfoResource $userinfo = null;

    /**
     * Add user information to the response
     */
    public function withUserInfo(UserInfoResource $userinfo): void
    {
        $this->userinfo = $userinfo;
        $this->user_id = $userinfo->id;
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $extra = $this->userinfo ? $this->userinfo->toArray($request) : [];

        return [
            // Authentication token
            'access_token' => $this->resource->access_token,
            // Refresh token
            'refresh_token' => $this->resource->refresh_token,
            // Token type
            'token_type' => \strtolower($this->resource->token_type),
            // Token expiration time (in seconds)
            'expires_in' => (int) $this->resource->expires_in,
            // Response status
            'status' => $this->status,
            // @var string Response message
            'message' => $this->when(isset($this->message), $this->message),
            // @var array Payment checkout information (on signup)
            'checkout' => $this->when(isset($this->checkout), $this->checkout),
            // @var array{'email': string, 'password': string} New user credentials (on device signup)
            'credentials' => $this->when(isset($this->credentials), $this->credentials),
            // @var DeviceInfoResource Device information (on device signup)
            'device' => $this->when(isset($this->device), $this->device),
            // @var int User identifier
            'id' => $this->user_id,
            // @var UserInfoResource User information
            'user' => $this->when(isset($this->userinfo), $this->userinfo),
        ];
    }
}
