<?php

namespace App\Http\Resources;

use App\Fs\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Filesystem item information response
 *
 * @mixin Item
 */
class FsItemInfoResource extends FsItemResource
{
    public ?string $downloadUrl = null;

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        // TODO: Handle read-write/full access rights
        $isOwner = Auth::guard()->user()->id == $this->resource->user_id;

        $parent = $this->resource->parents()->first();

        return [
            $this->merge(parent::toArray($request)),

            'canUpdate' => $isOwner,
            'canDelete' => $isOwner,
            'isOwner' => $isOwner,

            // @var string Unauthenticated doawnload location for the file content
            'downloadUrl' => $this->when(!empty($this->downloadUrl), $this->downloadUrl),

            // @var ?string Parent folder identifier
            'parentId' => $parent?->id,
        ];
    }
}
