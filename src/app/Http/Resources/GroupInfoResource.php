<?php

namespace App\Http\Resources;

use App\Group;
use App\Http\Controllers\API\V4\GroupsController;
use App\Http\Controllers\API\V4\SkusController;
use Illuminate\Http\Request;

/**
 * Group information response
 */
class GroupInfoResource extends GroupResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            $this->merge(parent::toArray($request)),

            // @var int Group status
            'status' => $this->resource->status,
            // Group creation date-time
            'created_at' => (string) $this->resource->created_at,
            // Group modification date-time
            'updated_at' => (string) $this->resource->updated_at,
            // @var string|null Group deletion date-time
            'deleted_at' => (string) $this->resource->deleted_at,

            // @var array<string, mixed> Group configuration, e.g. spf whitelist
            'config' => $this->resource->getConfig(),

            // @var array<string> List of group members (email addresses)
            'members' => $this->resource->getAddresses(),

            // @var array Extended status/permissions information
            'statusInfo' => GroupsController::statusInfo($this->resource),

            // Entitlements/Wallet information
            $this->merge(SkusController::objectEntitlements($this->resource)),
        ];
    }
}
