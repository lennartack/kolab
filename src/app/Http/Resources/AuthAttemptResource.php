<?php

namespace App\Http\Resources;

use App\AuthAttempt;
use Illuminate\Http\Request;

/**
 * AuthAttempt response
 *
 * @mixin AuthAttempt
 */
class AuthAttemptResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            // TODO: Do we need all properties?
            // TODO: Document properties for Scramble
            $this->merge(parent::toArray($request)),
        ];
    }
}
