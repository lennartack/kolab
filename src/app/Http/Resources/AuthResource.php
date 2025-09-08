<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Authentication response
 */
class AuthResource extends JsonResource
{
    public string $status = 'success';
    public ?int $user_id = null;

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
            // @var int User identifier
            'id' => $this->user_id,
            // @var UserInfoResource User information
            'user' => $this->when(isset($this->userinfo), $this->userinfo),
        ];
    }
}
