<?php

namespace App\Http\Resources;

use App\Meet\Room;
use App\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Room information response
 */
class RoomInfoResource extends RoomResource
{
    // Current user permission
    public ?Permission $permission = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $user = Auth::guard()->user();
        $isOwner = $user->canDelete($this->resource);
        $config = $this->resource->getConfig();

        // Room sharees can't manage/see room ACL
        if ($this->permission) {
            unset($config['acl']);
        }

        return [
            $this->merge(parent::toArray($request)),

            // @var array<string, mixed> Room configuration
            'config' => $config,

            // @var bool Can the user update the room?
            'canUpdate' => $isOwner || $this->resource->permissions()->where('user', $user->email)->exists(),

            // @var bool Can the user delete the room?
            'canDelete' => $isOwner && $user->wallet()->isController($user),

            // @var bool Can the user share the room?
            'canShare' => $isOwner && $this->resource->hasSku('group-room'),

            // @var bool Is the user an owner of this room?
            'isOwner' => $isOwner,

            // Entitlements/Wallet information
            $this->merge($this->objectEntitlements()),
        ];
    }
}
