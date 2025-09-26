<?php

namespace App\Http\Resources;

use App\Entitlement;
use App\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

/**
 * Common resource.
 */
class ApiResource extends JsonResource
{
    /**
     * Check if an authenticated user is admin or reseller
     */
    public static function isAdmin(): bool
    {
        $user = Auth::guard()->user();
        return $user && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_RESELLER]);
    }

    /**
     * Include SKUs/Wallet information in the object's response.
     *
     * @param object $object User/Domain/etc object
     */
    public static function objectEntitlements($object): array
    {
        $wallet = $object->wallet();

        return [
            // Entitlements information
            'skus' => Entitlement::objectEntitlementsSummary($object),
            // Wallet information
            'wallet' => $wallet ? new WalletResource($wallet) : null,
        ];
    }
}
