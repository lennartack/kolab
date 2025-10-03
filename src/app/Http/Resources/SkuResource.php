<?php

namespace App\Http\Resources;

use App\Sku;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SKU response
 *
 * @mixin Sku
 */
class SkuResource extends ApiResource
{
    public bool $controllerOnly = false;
    public ?string $type = null;
    public int $prio = 0;
    public array $metadata = [];

    /**
     * Create a new resource instance.
     *
     * @param mixed $resource
     */
    public function __construct($resource)
    {
        parent::__construct($resource);

        $this->metadata = $resource->handler_class::metadata($resource);
        $this->controllerOnly = !empty($this->metadata['controllerOnly']);
        $this->prio = $this->metadata['prio'];
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            // @var string SKU identifier
            'id' => $this->resource->id,
            // @var string SKU identifier
            'title' => $this->resource->title,
            // @var string SKU name
            'name' => $this->resource->name,
            // @var string SKU description
            'description' => $this->resource->description,
            // @var int SKU (monthly) cost (in cents)
            'cost' => $this->resource->cost,
            // @var bool SKU is active
            'active' => $this->resource->active,
            // @var string SKU period (monthly, yearly)
            'period' => $this->resource->period,
            // @var int Number of free units
            'units_free' => $this->resource->units_free,

            // @var string SKU type
            'type' => $this->metadata['type'],
            // @var string SKU handler class
            'handler' => $this->metadata['handler'],
            // @var bool Is the SKU readonly?
            'readonly' => $this->metadata['readonly'] ?? false,
            // @var bool Is the SKU enabled?
            'enabled' => $this->metadata['enabled'] ?? false,
            // @var int SKU priority (for list order)
            'prio' => $this->metadata['prio'],
            // @var array<string> Forbidden SKUs (by handler name)
            'forbidden' => $this->metadata['forbidden'] ?? [],
            // @var array<string> Exclusive SKUs (by handler name)
            'exclusive' => $this->metadata['exclusive'] ?? [],
            // @var array<string> Required SKUs (by handler name)
            'required' => $this->metadata['required'] ?? [],
            // @var array<string, mixed> SKU value range
            'range' => $this->when(isset($this->metadata['range']), $this->metadata['range'] ?? []),

            // Cost for a new object of the specified type
            'nextCost' => $this->when(!empty($this->type), fn () => $this->nextCost()),
        ];
    }

    /**
     * Calculate SKU cost for a new object of the specified type
     */
    private function nextCost(): int
    {
        $wallet = Auth::guard()->user()->wallet();
        $nextCost = $this->resource->cost;

        if ($wallet && $this->resource->cost && $this->resource->units_free) {
            $count = $wallet->entitlements()->where('sku_id', $this->resource->id)->count();
            if ($count < $this->resource->units_free) {
                $nextCost = 0;
            }
        }

        return $nextCost;
    }
}
