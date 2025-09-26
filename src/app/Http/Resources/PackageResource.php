<?php

namespace App\Http\Resources;

use App\Package;
use Illuminate\Http\Request;

/**
 * Package response
 *
 * @mixin Package
 */
class PackageResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            // Package identifier
            'id' => $this->resource->id,
            // Package title
            'title' => $this->resource->title,
            // Package name
            'name' => $this->resource->name,
            // Package description
            'description' => $this->resource->description,
            // Package cost (in cents)
            'cost' => $this->resource->cost(),
            // Is this a User or Domain package?
            'isDomain' => $this->resource->isDomain(),
        ];
    }
}
