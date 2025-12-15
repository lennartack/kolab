<?php

namespace App\Http\Resources;

use App\Fs\Item;
use Illuminate\Http\Request;

/**
 * Filesystem item response
 *
 * @mixin Item
 */
class FsItemResource extends ApiResource
{
    public const TYPE_COLLECTION = 'collection';
    public const TYPE_FILE = 'file';
    public const TYPE_UNKNOWN = 'unknown';

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $is_file = false;
        if ($this->resource->isCollection()) {
            $type = self::TYPE_COLLECTION;
        } elseif ($this->resource->isFile()) {
            $is_file = true;
            $type = self::TYPE_FILE;
        } else {
            $type = self::TYPE_UNKNOWN;
        }

        $keys = ['name', 'size', 'mimetype'];
        $props = [];
        if (!isset($this->resource->name) || ($is_file && (!isset($this->resource->size) || !isset($this->resource->mimetype)))) {
            $props = array_filter($this->resource->getProperties($keys));
        } else {
            foreach ($keys as $key) {
                $props[$key] = $this->resource->{$key} ?? null;
            }
        }

        return [
            // @var string Item identifier
            'id' => $this->resource->id,
            // @var string Item type (collection, file, unknown)
            'type' => $type,
            // @var string<date-time> Creation time
            'created_at' => $this->resource->created_at->toDateTimeString(),
            // @var string<date-time> Last update time
            'updated_at' => $this->resource->updated_at->toDateTimeString(),

            // @var string Item name
            'name' => $props['name'] ?? '',
            // @var int File size
            'size' => $this->when($is_file, (int) ($props['size'] ?? 0)),
            // @var string File content type
            'mimetype' => $this->when($is_file && isset($props['mimetype']), $props['mimetype'] ?? ''),
        ];
    }
}
