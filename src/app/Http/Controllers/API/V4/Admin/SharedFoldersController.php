<?php

namespace App\Http\Controllers\API\V4\Admin;

use App\Http\Resources\SharedFolderResource;
use App\SharedFolder;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharedFoldersController extends \App\Http\Controllers\API\V4\SharedFoldersController
{
    /**
     * Search for shared folders
     */
    public function index(): JsonResponse
    {
        $search = trim(request()->input('search'));
        $owner = trim(request()->input('owner'));
        $result = collect([]);

        if ($owner) {
            if ($owner = User::find($owner)) {
                $result = $owner->sharedFolders(false)->orderBy('name')->get();
            }
        } elseif (!empty($search)) {
            if ($folder = SharedFolder::where('email', $search)->first()) {
                $result->push($folder);
            }
        }

        $result = [
            'list' => SharedFolderResource::collection($result),
            'count' => count($result),
            'message' => self::trans('app.search-foundxshared-folders', ['x' => count($result)]),
        ];

        return response()->json($result);
    }

    /**
     * Create a new shared folder.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->errorResponse(404);
    }
}
