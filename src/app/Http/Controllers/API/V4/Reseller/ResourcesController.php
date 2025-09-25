<?php

namespace App\Http\Controllers\API\V4\Reseller;

use App\Http\Resources\ResourceResource;
use App\Resource;
use App\User;
use Illuminate\Http\JsonResponse;

class ResourcesController extends \App\Http\Controllers\API\V4\Admin\ResourcesController
{
    /**
     * Search for resources
     */
    public function index(): JsonResponse
    {
        $search = trim(request()->input('search'));
        $owner = trim(request()->input('owner'));
        $result = collect([]);

        if ($owner) {
            if ($owner = User::withSubjectTenantContext()->find($owner)) {
                $result = $owner->resources(false)->orderBy('name')->get();
            }
        } elseif (!empty($search)) {
            if ($resource = Resource::withSubjectTenantContext()->where('email', $search)->first()) {
                $result->push($resource);
            }
        }

        $result = [
            'list' => ResourceResource::collection($result),
            'count' => count($result),
            'message' => self::trans('app.search-foundxresources', ['x' => count($result)]),
        ];

        return response()->json($result);
    }
}
