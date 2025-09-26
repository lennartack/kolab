<?php

namespace App\Http\Resources;

use App\Http\Controllers\API\V4\SharedFoldersController;
use Illuminate\Http\Request;

/**
 * Shared folder information response
 */
class SharedFolderInfoResource extends SharedFolderResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            $this->merge(parent::toArray($request)),

            // @var int Folder status
            'status' => $this->resource->status,
            // Folder creation date-time
            'created_at' => (string) $this->resource->created_at,
            // Folder modification date-time
            'updated_at' => (string) $this->resource->updated_at,
            // @var string|null Folder deletion date-time
            'deleted_at' => (string) $this->resource->deleted_at,

            // @var array Folder aliases (email addresses)
            'aliases' => $this->resource->aliases()->pluck('alias')->all(),

            // @var array<string, mixed> Folder configuration
            'config' => $this->resource->getConfig(),

            // @var array Extended status/permissions information
            'statusInfo' => SharedFoldersController::statusInfo($this->resource),

            // Entitlements/Wallet information
            $this->merge(self::objectEntitlements($this->resource)),
        ];
    }
}
