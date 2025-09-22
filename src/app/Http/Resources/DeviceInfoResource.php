<?php

namespace App\Http\Resources;

use App\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Device response
 *
 * @mixin Device
 */
class DeviceInfoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            // Device registration date-time
            'created_at' => (string) $this->resource->created_at,
        ];
    }
}
