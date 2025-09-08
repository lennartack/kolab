<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\Controller;
use App\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LicenseController extends Controller
{
    /**
     * Get the information on any license for the user.
     *
     * @param string $type License type
     *
     * @return JsonResponse The response
     */
    public function license(Request $request, string $type)
    {
        $user = $this->guard()->user();

        if (!$this->checkTenant($user)) {
            return $this->errorResponse(404);
        }

        $licenses = $user->licenses()->where('type', $type)->orderBy('created_at')->get();

        // No licenses for the user, take one if available
        if (!count($licenses)) {
            DB::beginTransaction();

            $license = License::withObjectTenantContext($user)
                ->where('type', $type)
                ->whereNull('user_id')
                ->limit(1)
                ->lockForUpdate()
                ->first();

            if ($license) {
                $license->user_id = $user->id;
                $license->save();

                $licenses = \collect([$license]);
            }

            DB::commit();
        }

        // Slim down the result set
        $licenses = $licenses->map(static function ($license) {
            return [
                'key' => $license->key,
                'type' => $license->type,
            ];
        });

        return response()->json([
            'status' => 'success',
            // @var array List of licenses (properties: key, type)
            'list' => $licenses,
            // @var int Number of entries in the list
            'count' => count($licenses),
            // @var bool Indicates that there are more entries available
            'hasMore' => false, // TODO
        ]);
    }
}
