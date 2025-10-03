<?php

namespace App\Http\Resources;

use App\Http\Controllers\RelationController;
use App\Meet\Room;
use Illuminate\Http\Request;

/**
 * Room response
 *
 * @mixin Room
 */
class RoomResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $state = RelationController::objectState($this->resource);

        return [
            // Room identifier
            'id' => $this->resource->id,
            // Room name
            'name' => $this->resource->name,
            // Room description
            'description' => $this->resource->description,

            $this->mergeWhen(self::isAdmin(), [
                /*
                 * @var string Room creation date-time
                 * @format date-time
                 */
                'created_at' => (string) $this->resource->created_at,
                /*
                 * @var string Room deletion date-time
                 * @format date-time
                 */
                'deleted_at' => (string) $this->resource->deleted_at,
            ]),

            // @var bool Is room deleted?
            'isDeleted' => $state['isDeleted'] ?? false,
            // @var bool Readiness state
            'isReady' => $state['isReady'],
        ];
    }
}
