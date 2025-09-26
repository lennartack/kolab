<?php

namespace App\Http\Resources;

use App\Http\Controllers\API\V4\ResourcesController;
use App\Resource;
use Illuminate\Http\Request;

/**
 * Resource information response
 */
class ResourceInfoResource extends GroupResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            $this->merge(parent::toArray($request)),

            // @var int Resource status
            'status' => $this->resource->status,
            // Resource creation date-time
            'created_at' => (string) $this->resource->created_at,
            // Resource modification date-time
            'updated_at' => (string) $this->resource->updated_at,
            // @var string|null Resource deletion date-time
            'deleted_at' => (string) $this->resource->deleted_at,

            // @var array<string, mixed> Resource configuration
            'config' => $this->resource->getConfig(),

            // @var array Extended status/permissions information
            'statusInfo' => ResourcesController::statusInfo($this->resource),

            // Entitlements/Wallet information
            $this->merge(self::objectEntitlements($this->resource)),
        ];
    }
}
