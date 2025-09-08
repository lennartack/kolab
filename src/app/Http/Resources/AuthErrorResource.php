<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Authentication error response
 */
class AuthErrorResource extends JsonResource
{
    public string $status = 'error';
    public string $message;
    public bool $password_expired = false;
    public ?int $user_id = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            // Response status
            'status' => $this->status,
            // Error message
            'message' => $this->message,
            // Indicates an expired password
            'password_expired' => $this->password_expired,
            // @var int User identifier
            'id' => $this->when(isset($this->user_id), $this->user_id),
        ];
    }
}
