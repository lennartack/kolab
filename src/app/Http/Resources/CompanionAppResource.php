<?php

namespace App\Http\Resources;

use App\CompanionApp;
use Illuminate\Http\Request;

/**
 * CompanionApp response
 *
 * @mixin CompanionApp
 */
class CompanionAppResource extends ApiResource
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
