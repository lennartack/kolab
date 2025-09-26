<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\ResourceController;
use App\Http\Resources\PackageResource;
use App\Package;
use Illuminate\Http\JsonResponse;

class PackagesController extends ResourceController
{
    /**
     * Display a listing of packages.
     */
    public function index(): JsonResponse
    {
        // TODO: Packages should have an 'active' flag too, I guess
        $result = Package::withSubjectTenantContext()->select()->orderBy('title')->get();

        return response()->json([
            'status' => 'success',
            // @var string Response message
            'message' => self::trans("app.search-foundxpackages", ['x' => count($result)]),
            // List of packages
            'list' => PackageResource::collection($result),
            // @var int Number of entries in the list
            'count' => count($result),
            // @var bool Indicates that there are more entries available
            'hasMore' => false,
        ]);
    }
}
