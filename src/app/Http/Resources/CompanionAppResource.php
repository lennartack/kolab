<?php

namespace App\Http\Resources;

use App\CompanionApp;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CompanionApp response
 *
 * @mixin CompanionApp
 */
class CompanionAppResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            $this->merge(parent::toArray($request)),
            // @var bool Indicates the app status
            'isReady' => $this->resource->isPaired(),
        ];
    }
}
