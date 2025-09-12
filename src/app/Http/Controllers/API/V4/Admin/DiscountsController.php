<?php

namespace App\Http\Controllers\API\V4\Admin;

use App\Discount;
use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\JsonResponse;

class DiscountsController extends Controller
{
    /**
     * Returns (active) discounts defined in the system for the user context.
     *
     * @param int $id User identifier
     */
    public function userDiscounts($id): JsonResponse
    {
        $user = User::find($id);

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        $discounts = Discount::withObjectTenantContext($user)
            ->where('active', true)
            ->orderBy('discount')
            ->get()
            ->map(static function ($discount) {
                $label = $discount->discount . '% - ' . $discount->description;

                if ($discount->code) {
                    $label .= " [{$discount->code}]";
                }

                return [
                    'id' => $discount->id,
                    'discount' => $discount->discount,
                    'code' => $discount->code,
                    'description' => $discount->description,
                    'label' => $label,
                ];
            });

        return response()->json([
            'status' => 'success',
            'list' => $discounts,
            'count' => count($discounts),
        ]);
    }
}
