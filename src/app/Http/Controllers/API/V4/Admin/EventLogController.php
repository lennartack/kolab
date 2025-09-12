<?php

namespace App\Http\Controllers\API\V4\Admin;

use App\EventLog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventLogController extends Controller
{
    /**
     * Listing of eventlog entries.
     *
     * @param Request $request     HTTP Request
     * @param string  $object_type Object type
     * @param string  $object_id   Object id
     */
    public function index(Request $request, string $object_type, string $object_id): JsonResponse
    {
        $object_type = "App\\" . ucfirst($object_type);

        if (!class_exists($object_type)) {
            return $this->errorResponse(404);
        }

        $object = (new $object_type())->find($object_id);

        if (!$this->checkTenant($object)) {
            return $this->errorResponse(404);
        }

        $page = (int) ($request->input('page')) ?: 1;
        $pageSize = 20;
        $hasMore = false;

        $result = EventLog::where('object_id', $object_id)
            ->where('object_type', $object_type)
            ->orderBy('created_at', 'desc')
            ->limit($pageSize + 1)
            ->offset($pageSize * ($page - 1))
            ->get();

        if (count($result) > $pageSize) {
            $result->pop();
            $hasMore = true;
        }

        $result = $result->map(static function ($event) {
            return [
                'id' => $event->id,
                'comment' => $event->comment,
                'createdAt' => $event->created_at->toDateTimeString(),
                'event' => $event->eventName(),
                'data' => $event->data,
                'user' => $event->user_email,
            ];
        });

        return response()->json([
            'list' => $result,
            'count' => count($result),
            'hasMore' => $hasMore,
        ]);
    }
}
