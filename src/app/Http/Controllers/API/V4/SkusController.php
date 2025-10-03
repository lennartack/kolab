<?php

namespace App\Http\Controllers\API\V4;

use App\Handlers\Mailbox;
use App\Http\Controllers\ResourceController;
use App\Http\Resources\SkuResource;
use App\Sku;
use App\Wallet;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class SkusController extends ResourceController
{
    /**
     * List of active SKUs.
     */
    #[QueryParameter('type', description: 'SKU type', type: 'string')]
    public function index(): JsonResponse
    {
        $type = request()->input('type');
        $wallet = $this->guard()->user()->wallet();

        // Note: Order by title for consistent ordering in tests
        $list = Sku::withSubjectTenantContext()->where('active', true)->orderBy('title')
            ->get()
            ->transform(function ($sku, $type) {
                return $this->skuElement($sku, $type);
            })
            ->filter(static function ($sku) use ($type) {
                return $sku && (!$type || $sku->metadata['type'] === $type);
            })
            ->sortByDesc('prio')
            ->values();

        return response()->json([
            // @var array<SkuResource> List of SKUs
            'list' => $list,
            // @var int Number of entries in the list
            'count' => $list->count(),
        ]);
    }

    /**
     * Return SKUs available to the specified entitleable object.
     *
     * @param object $object Entitleable object
     */
    public static function objectSkus($object): JsonResponse
    {
        $user = Auth::guard()->user();

        // Note: Order by title for consistent ordering in tests
        $list = Sku::withObjectTenantContext($object)->orderBy('title')
            ->get()
            ->filter(static function ($sku) use ($object) {
                return class_exists($sku->handler_class)
                    && $object::class == $sku->handler_class::entitleableClass()
                    && $sku->handler_class::isAvailable($sku, $object);
            })
            ->transform(function ($sku) {
                return self::skuElement($sku);
            })
            ->filter(static function ($sku) use ($user) {
                return !empty($sku) && (empty($sku->controllerOnly) || $user->wallet()->isController($user));
            })
            ->sortByDesc('prio')
            ->values();

        return response()->json([
            // @var array<SkuResource> List of SKUs
            'list' => $list,
            // @var int Number of entries in the list
            'count' => $list->count(),
        ]);
    }

    /**
     * Update object entitlements.
     *
     * @param object  $object The object for update
     * @param array   $rSkus  List of SKU IDs requested for the object in the form [id=>qty]
     * @param ?Wallet $wallet The target wallet
     */
    public static function updateEntitlements($object, $rSkus, $wallet = null): void
    {
        if (!is_array($rSkus)) {
            return;
        }

        if (!\config('app.with_subscriptions')) {
            throw new \Exception("Subscriptions disabled");
        }

        // available SKUs, [id => obj]
        $skus = Sku::withObjectTenantContext($object)->get()->mapWithKeys(
            static function ($sku) {
                return [$sku->id => $sku];
            }
        );

        // existing object SKUs, [id => total]
        $eSkus = $object->entitlements()->groupBy('sku_id')->selectRaw('count(*) as total, sku_id')->get()->mapWithKeys(
            static function ($e) {
                return [$e->sku_id => $e->total];
            }
        )->all();

        // compare current and requested state and apply changes (add/remove entitlements)
        foreach ($skus as $skuID => $sku) {
            $e = array_key_exists($skuID, $eSkus) ? $eSkus[$skuID] : 0;
            $r = array_key_exists($skuID, $rSkus) ? $rSkus[$skuID] : 0;

            if (!class_exists($sku->handler_class) || !is_a($object, $sku->handler_class::entitleableClass())) {
                continue;
            }

            if ($sku->handler_class == Mailbox::class) {
                if ($r != 1) {
                    throw new \Exception("Invalid quantity of mailboxes");
                }
            }

            if ($e > $r) {
                // remove those entitled more than existing
                $object->removeSku($sku, $e - $r);
            } elseif ($e < $r) {
                // add those requested more than entitled
                $object->assignSku($sku, $r - $e, $wallet);
            }
        }
    }

    /**
     * Convert SKU information to metadata used by UI to
     * display the form control
     *
     * @param Sku    $sku  SKU object
     * @param string $type Type filter
     */
    protected static function skuElement($sku, $type = null): ?SkuResource
    {
        if (!class_exists($sku->handler_class)) {
            \Log::warning("Missing handler {$sku->handler_class}");
            return null;
        }

        $resource = new SkuResource($sku);

        // ignore incomplete handlers
        if (empty($resource->metadata['type'])) {
            \Log::warning("Incomplete handler {$sku->handler_class}");
            return null;
        }

        $resource->type = $type;

        return $resource;
    }
}
