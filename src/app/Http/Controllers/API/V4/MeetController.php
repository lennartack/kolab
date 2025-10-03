<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoomSessionResource;
use App\Meet\Room;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class MeetController extends Controller
{
    /**
     * Join the room session.
     *
     * Each room has one owner, and the room isn't open until the owner
     * joins (and effectively creates the session).
     *
     * @param string $id Room identifier (name)
     */
    #[BodyParameter('password', description: 'Room password', type: 'string')]
    #[BodyParameter('requestId', description: 'Unique client identifier', type: 'string')]
    #[BodyParameter('picture', description: 'User image', type: 'string')]
    #[BodyParameter('nickname', description: 'User nickname', type: 'string')]
    #[BodyParameter('canPublish', description: 'Client has media device(s)', type: 'bool')]
    public function joinRoom($id): JsonResponse
    {
        $room = Room::where('name', $id)->first();

        // Room does not exist, or the owner is deleted
        if (!$room || !($wallet = $room->wallet()) || !$wallet->owner || $wallet->owner->isDegraded(true)) {
            return $this->errorResponse(404, self::trans('meet.room-not-found'));
        }

        $user = Auth::guard()->user();
        $init = !empty(request()->input('init'));
        $settings = $room->getSettings(['locked', 'nomedia', 'password']);
        $password = (string) $settings['password'];

        $response = new RoomSessionResource($settings);
        $response->isOwner = $user && (
            $user->id == $wallet->owner->id || $room->permissions()->where('user', $user->email)->exists()
        );

        // There's no existing session
        if (!$room->hasSession()) {
            // Participants can't join the room until the session is created by the owner
            if (!$response->isOwner) {
                $response->code = 323;
                return $response->response()->setStatusCode(422);
            }

            // The room owner can create the session on request
            if (!$init) {
                $response->code = 324;
                return $response->response()->setStatusCode(422);
            }

            $session = $room->createSession();

            if (empty($session)) {
                return $this->errorResponse(500, self::trans('meet.session-create-error'));
            }
        }

        // Validate room password
        if (!$response->isOwner && strlen($password)) {
            $request_password = request()->input('password');
            if ($request_password !== $password) {
                $response->code = 325;
                return $response->response()->setStatusCode(422);
            }
        }

        // Handle locked room
        if (!$response->isOwner && $settings['locked']) {
            $nickname = request()->input('nickname');
            $picture = request()->input('picture');
            $requestId = request()->input('requestId');

            $request = $requestId ? $room->requestGet($requestId) : null;

            // Request already has been processed (not accepted yet, but it could be denied)
            if (empty($request['status']) || $request['status'] != Room::REQUEST_ACCEPTED) {
                if (!$request) {
                    if (empty($nickname) || empty($requestId) || !preg_match('/^[a-z0-9]{8,32}$/i', $requestId)) {
                        $response->code = 326;
                        return $response->response()->setStatusCode(422);
                    }

                    if (empty($picture)) {
                        $svg = file_get_contents(resource_path('images/user.svg'));
                        $picture = 'data:image/svg+xml;base64,' . base64_encode($svg);
                    } elseif (!preg_match('|^data:image/png;base64,[a-zA-Z0-9=+/]+$|', $picture)) {
                        $response->code = 326;
                        return $response->response()->setStatusCode(422);
                    }

                    // TODO: Resize when big/make safe the user picture?

                    $request = ['nickname' => $nickname, 'requestId' => $requestId, 'picture' => $picture];

                    if (!$room->requestSave($requestId, $request)) {
                        // FIXME: should we use error code 500?
                        $response->code = 326;
                        return $response->response()->setStatusCode(422);
                    }

                    // Send the request (signal) to all moderators
                    $room->signal('joinRequest', $request, Room::ROLE_MODERATOR);
                }

                $response->code = 327;
                return $response->response()->setStatusCode(422);
            }
        }

        if (!$init) {
            $response->code = 322;
            return $response->response()->setStatusCode(422);
        }

        // Choose the connection role
        $canPublish = !empty(request()->input('canPublish')) && (empty($settings['nomedia']) || $response->isOwner);
        $response->role = $canPublish ? Room::ROLE_PUBLISHER : Room::ROLE_SUBSCRIBER;
        if ($response->isOwner) {
            $response->role |= Room::ROLE_MODERATOR | Room::ROLE_OWNER;
        }

        // Create session token for the current user/connection
        $response->token = $room->getSessionToken($response->role);

        if (empty($response->token)) {
            return $this->errorResponse(500, self::trans('meet.session-join-error'));
        }

        return $response->response();
    }

    /**
     * Webhook as triggered from the Meet server
     */
    public function webhook(Request $request): Response
    {
        \Log::debug($request->getContent());

        // Authenticate the request
        if ($request->headers->get('X-Auth-Token') != \config('meet.webhook_token')) {
            return response('Unauthorized', 403);
        }

        $sessionId = (string) $request->input('roomId');
        $event = (string) $request->input('event');

        switch ($event) {
            case 'roomClosed':
                // When all participants left the room the server will dispatch roomClosed
                // event. We'll remove the session reference from the database.
                $room = Room::where('session_id', $sessionId)->first();

                if ($room) {
                    $room->session_id = null;
                    $room->save();
                }

                break;
            case 'joinRequestAccepted':
            case 'joinRequestDenied':
                $room = Room::where('session_id', $sessionId)->first();

                if ($room) {
                    $method = $event == 'joinRequestAccepted' ? 'requestAccept' : 'requestDeny';

                    $room->{$method}($request->input('requestId'));
                }

                break;
        }

        return response('Success', 200);
    }
}
