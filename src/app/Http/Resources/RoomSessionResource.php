<?php

namespace App\Http\Resources;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Meet room session response
 */
class RoomSessionResource extends ApiResource
{
    public ?int $code = null;
    public bool $isOwner = false;
    public ?string $token = null;
    public ?int $role = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            // @var int Response status code
            'code' => $this->when(isset($this->code), $this->code),
            // @var string Response message
            'message' => $this->when(isset($this->code), $this->message($this->code)),
            // @var string Response status
            'status' => $this->code ? 'error' : 'success',
            // Room configuration
            'config' => [
                // @var bool Whether the room is locked
                'locked' => $this->resource['locked'] === 'true',
                // @var bool Whether use of media is disabled in the room
                'nomedia' => $this->resource['nomedia'] === 'true',
                // @var string Room password
                'password' => $this->isOwner ? (string) $this->resource['password'] : '',
                // @var bool Whether the password is required or not
                'requires_password' => !$this->isOwner && strlen((string) $this->resource['password']),
            ],
            // @var int User role in the room
            'role' => $this->when(isset($this->role), $this->role),
            // @var string Session token
            'token' => $this->when(isset($this->token), $this->token),
        ];
    }

    /**
     * Get a status message for the status code
     */
    private function message($code): ?string
    {
        switch ((int) $code) {
            case 323:
            case 324:
                return Controller::trans('meet.session-not-found');
            case 325:
                return Controller::trans('meet.session-password-error');
            case 326:
            case 327:
                return Controller::trans('meet.session-room-locked-error');
        }

        return null;
    }
}
