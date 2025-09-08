<?php

namespace App\Http\Controllers\API\V4;

use App\Entitlement;
use App\Http\Controllers\RelationController;
use App\Meet\Room;
use App\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RoomsController extends RelationController
{
    /** @var string Resource localization label */
    protected $label = 'room';

    /** @var string Resource model name */
    protected $model = Room::class;

    /** @var array Resource listing order (column names) */
    protected $order = ['name'];

    /** @var array Common object properties in the API response */
    protected $objectProps = ['name', 'description'];

    /**
     * Delete a room
     *
     * @param string $id Room identifier
     *
     * @return JsonResponse The response
     */
    public function destroy($id)
    {
        $room = $this->inputRoom($id);
        if (is_int($room)) {
            return $this->errorResponse($room);
        }

        $room->delete();

        return response()->json([
            'status' => 'success',
            'message' => self::trans("app.room-delete-success"),
        ]);
    }

    /**
     * List rooms.
     *
     * @return JsonResponse
     */
    public function index()
    {
        $user = $this->guard()->user();

        $shared = Room::whereIn('id', static function ($query) use ($user) {
            $query->select('permissible_id')
                ->from('permissions')
                ->where('permissible_type', Room::class)
                ->where('user', $user->email);
        });

        // Create a "private" room for the user
        if (!$user->rooms()->count()) {
            $room = Room::create();
            $room->assignToWallet($user->wallets()->first());
        }

        $rooms = $user->rooms(true)
            ->union($shared)
            ->orderBy('name')
            ->get()
            ->map(function ($room) {
                return $this->objectToClient($room);
            });

        $result = [
            // @var array List of rooms
            'list' => $rooms,
            // @var int Number of entries in the list
            'count' => count($rooms),
        ];

        return response()->json($result);
    }

    /**
     * Set the room configuration.
     *
     * @param int|string $id Room identifier (or name)
     *
     * @return JsonResponse|void
     */
    public function setConfig($id)
    {
        $room = $this->inputRoom($id, Permission::ADMIN, $permission);
        if (is_int($room)) {
            return $this->errorResponse($room);
        }

        $request = request()->input();

        // Room sharees can't manage room ACL
        if ($permission) {
            unset($request['acl']);
        }

        $errors = $room->setConfig($request);

        if (!empty($errors)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => self::trans("app.room-setconfig-success"),
        ]);
    }

    /**
     * Get room information.
     *
     * @param string $id Room identifier
     *
     * @return JsonResponse
     */
    public function show($id)
    {
        $room = $this->inputRoom($id, Permission::READ, $permission);
        if (is_int($room)) {
            return $this->errorResponse($room);
        }

        $wallet = $room->wallet();
        $user = $this->guard()->user();

        $response = $this->objectToClient($room, true);

        unset($response['session_id']);

        $response['config'] = $room->getConfig();

        // Room sharees can't manage/see room ACL
        if ($permission) {
            unset($response['config']['acl']);
        }

        $response['skus'] = Entitlement::objectEntitlementsSummary($room);
        $response['wallet'] = $wallet->toArray();

        if ($wallet->discount) {
            $response['wallet']['discount'] = $wallet->discount->discount;
            $response['wallet']['discount_description'] = $wallet->discount->description;
        }

        $isOwner = $user->canDelete($room);
        $response['canUpdate'] = $isOwner || $room->permissions()->where('user', $user->email)->exists();
        $response['canDelete'] = $isOwner && $user->wallet()->isController($user);
        $response['canShare'] = $isOwner && $room->hasSKU('group-room');
        $response['isOwner'] = $isOwner;

        return response()->json($response);
    }

    /**
     * Get a list of SKUs available to the room.
     *
     * @param int $id Room identifier
     *
     * @return JsonResponse
     */
    public function skus($id)
    {
        $room = $this->inputRoom($id);
        if (is_int($room)) {
            return $this->errorResponse($room);
        }

        return SkusController::objectSkus($room);
    }

    /**
     * Create a new room.
     *
     * @param Request $request the API request
     *
     * @return JsonResponse The response
     */
    public function store(Request $request)
    {
        $user = $this->guard()->user();
        $wallet = $user->wallet();

        if (!$wallet->isController($user)) {
            return $this->errorResponse(403);
        }

        $v = Validator::make($request->all(), [
            'description' => 'nullable|string|max:191',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        DB::beginTransaction();

        $room = Room::create([
            'description' => $request->input('description'),
        ]);

        if (!empty($request->skus)) {
            SkusController::updateEntitlements($room, $request->skus, $wallet);
        } else {
            $room->assignToWallet($wallet);
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => self::trans("app.room-create-success"),
        ]);
    }

    /**
     * Update a room.
     *
     * @param Request $request the API request
     * @param string  $id      Room identifier
     *
     * @return JsonResponse The response
     */
    public function update(Request $request, $id)
    {
        $room = $this->inputRoom($id, Permission::ADMIN);
        if (is_int($room)) {
            return $this->errorResponse($room);
        }

        $v = Validator::make($request->all(), [
            'description' => 'nullable|string|max:191',
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        DB::beginTransaction();

        $room->description = request()->input('description');
        $room->save();

        SkusController::updateEntitlements($room, $request->skus);

        if (!$room->hasSKU('group-room')) {
            $room->setSetting('acl', null);
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => self::trans("app.room-update-success"),
        ]);
    }

    /**
     * Get the input room object, check permissions.
     *
     * @param int|string  $id         Room identifier (or name)
     * @param ?int        $rights     Required access rights
     * @param ?Permission $permission Room permission reference if the user has permissions
     *                                to the room and is not the owner
     *
     * @return Room|int File object or error code
     */
    protected function inputRoom($id, $rights = 0, &$permission = null): int|Room
    {
        if (!is_numeric($id)) {
            $room = Room::where('name', $id)->first();
        } else {
            $room = Room::find($id);
        }

        if (!$room) {
            return 404;
        }

        $user = $this->guard()->user();

        // Room owner (or another wallet controller)?
        if ($room->wallet()->isController($user)) {
            return $room;
        }

        if ($rights) {
            $permission = $room->permissions()->where('user', $user->email)->first();

            if ($permission && $permission->rights & $rights) {
                return $room;
            }
        }

        return 403;
    }
}
