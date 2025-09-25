<?php

namespace App\Http\Controllers\API\V4\Admin;

use App\Http\Resources\ResourceResource;
use App\Resource;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourcesController extends \App\Http\Controllers\API\V4\ResourcesController
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
            if ($owner = User::find($owner)) {
                $result = $owner->resources(false)->orderBy('name')->get();
            }
        } elseif (!empty($search)) {
            if ($resource = Resource::where('email', $search)->first()) {
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

    /**
     * Create a new resource.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->errorResponse(404);
    }
}
