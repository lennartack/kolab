<?php

namespace App\Http\Controllers\API\V4\Reseller;

use App\Group;
use App\User;
use Illuminate\Http\JsonResponse;

class GroupsController extends \App\Http\Controllers\API\V4\Admin\GroupsController
{
    /**
     * Search for groups
     */
    public function index(): JsonResponse
    {
        $search = trim(request()->input('search'));
        $owner = trim(request()->input('owner'));
        $result = collect([]);

        if ($owner) {
            if ($owner = User::withSubjectTenantContext()->find($owner)) {
                $result = $owner->groups(false)->orderBy('name')->get();
            }
        } elseif (!empty($search)) {
            if ($group = Group::withSubjectTenantContext()->where('email', $search)->first()) {
                $result->push($group);
            }
        }

        // Process the result
        $result = $result->map(
            function ($group) {
                return $this->objectToClient($group);
            }
        );

        $result = [
            'list' => $result,
            'count' => count($result),
            'message' => self::trans('app.search-foundxdistlists', ['x' => count($result)]),
        ];

        return response()->json($result);
    }
}
