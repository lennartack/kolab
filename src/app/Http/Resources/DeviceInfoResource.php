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
            /*
             * @var string Device registration date
             * @format date-time
             */
            'created_at' => (string) $this->resource->created_at,

            // Number of free months left
            'freeMonths' => $this->freeMonths(),
        ];
    }

    /**
     * Calculate number of free months left
     */
    private function freeMonths(): int
    {
        $until = (clone $this->created_at)->addYearWithoutOverflow()->floorMonth();
        $now = (clone \now())->floorMonth();

        $months = $now->diffInMonths($until);

        return max(0, $months); // No negative values
    }
}
