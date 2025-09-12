<?php

namespace App\Http\Controllers\API\V4\Admin;

use App\EventLog;
use App\Group;
use App\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GroupsController extends \App\Http\Controllers\API\V4\GroupsController
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
            if ($owner = User::find($owner)) {
                $result = $owner->groups(false)->orderBy('name')->get();
            }
        } elseif (!empty($search)) {
            if ($group = Group::where('email', $search)->first()) {
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

    /**
     * Create a new group.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->errorResponse(404);
    }

    /**
     * Suspend a group
     *
     * @param Request $request the API request
     * @param string  $id      Group identifier
     */
    public function suspend(Request $request, $id): JsonResponse
    {
        $group = Group::find($id);

        if (!$this->checkTenant($group)) {
            return $this->errorResponse(404);
        }

        $v = Validator::make($request->all(), ['comment' => 'nullable|string|max:1024']);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $group->suspend();

        EventLog::createFor($group, EventLog::TYPE_SUSPENDED, $request->comment);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.distlist-suspend-success'),
        ]);
    }

    /**
     * Un-Suspend a group
     *
     * @param Request $request the API request
     * @param string  $id      Group identifier
     */
    public function unsuspend(Request $request, $id): JsonResponse
    {
        $group = Group::find($id);

        if (!$this->checkTenant($group)) {
            return $this->errorResponse(404);
        }

        $v = Validator::make($request->all(), ['comment' => 'nullable|string|max:1024']);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $group->unsuspend();

        EventLog::createFor($group, EventLog::TYPE_UNSUSPENDED, $request->comment);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.distlist-unsuspend-success'),
        ]);
    }
}
