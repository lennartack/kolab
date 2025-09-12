<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\Controller;
use App\Meet\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class MeetController extends Controller
{
    /**
     * Join the room session. Each room has one owner, and the room isn't open until the owner
     * joins (and effectively creates the session).
     *
     * @param string $id Room identifier (name)
     */
    public function joinRoom($id): JsonResponse
    {
        $room = Room::where('name', $id)->first();

        // Room does not exist, or the owner is deleted
        if (!$room || !($wallet = $room->wallet()) || !$wallet->owner || $wallet->owner->isDegraded(true)) {
            return $this->errorResponse(404, self::trans('meet.room-not-found'));
        }

        $user = Auth::guard()->user();
        $isOwner = $user && (
            $user->id == $wallet->owner->id || $room->permissions()->where('user', $user->email)->exists()
        );
        $init = !empty(request()->input('init'));

        // There's no existing session
        if (!$room->hasSession()) {
            // Participants can't join the room until the session is created by the owner
            if (!$isOwner) {
                return $this->errorResponse(422, self::trans('meet.session-not-found'), ['code' => 323]);
            }

            // The room owner can create the session on request
            if (!$init) {
                return $this->errorResponse(422, self::trans('meet.session-not-found'), ['code' => 324]);
            }

            $session = $room->createSession();

            if (empty($session)) {
                return $this->errorResponse(500, self::trans('meet.session-create-error'));
            }
        }

        $settings = $room->getSettings(['locked', 'nomedia', 'password']);
        $password = (string) $settings['password'];

        $config = [
            'locked' => $settings['locked'] === 'true',
            'nomedia' => $settings['nomedia'] === 'true',
            'password' => $isOwner ? $password : '',
            'requires_password' => !$isOwner && strlen($password),
        ];

        $response = ['config' => $config];

        // Validate room password
        if (!$isOwner && strlen($password)) {
            $request_password = request()->input('password');
            if ($request_password !== $password) {
                $response['code'] = 325;
                return $this->errorResponse(422, self::trans('meet.session-password-error'), $response);
            }
        }

        // Handle locked room
        if (!$isOwner && $config['locked']) {
            $nickname = request()->input('nickname');
            $picture = request()->input('picture');
            $requestId = request()->input('requestId');

            $request = $requestId ? $room->requestGet($requestId) : null;

            $error = self::trans('meet.session-room-locked-error');

            // Request already has been processed (not accepted yet, but it could be denied)
            if (empty($request['status']) || $request['status'] != Room::REQUEST_ACCEPTED) {
                if (!$request) {
                    if (empty($nickname) || empty($requestId) || !preg_match('/^[a-z0-9]{8,32}$/i', $requestId)) {
                        return $this->errorResponse(422, $error, $response + ['code' => 326]);
                    }

                    if (empty($picture)) {
                        $svg = file_get_contents(resource_path('images/user.svg'));
                        $picture = 'data:image/svg+xml;base64,' . base64_encode($svg);
                    } elseif (!preg_match('|^data:image/png;base64,[a-zA-Z0-9=+/]+$|', $picture)) {
                        return $this->errorResponse(422, $error, $response + ['code' => 326]);
                    }

                    // TODO: Resize when big/make safe the user picture?

                    $request = ['nickname' => $nickname, 'requestId' => $requestId, 'picture' => $picture];

                    if (!$room->requestSave($requestId, $request)) {
                        // FIXME: should we use error code 500?
                        return $this->errorResponse(422, $error, $response + ['code' => 326]);
                    }

                    // Send the request (signal) to all moderators
                    $result = $room->signal('joinRequest', $request, Room::ROLE_MODERATOR);
                }

                return $this->errorResponse(422, $error, $response + ['code' => 327]);
            }
        }

        // Initialize connection tokens
        if ($init) {
            // Choose the connection role
            $canPublish = !empty(request()->input('canPublish')) && (empty($config['nomedia']) || $isOwner);
            $role = $canPublish ? Room::ROLE_PUBLISHER : Room::ROLE_SUBSCRIBER;
            if ($isOwner) {
                $role |= Room::ROLE_MODERATOR;
                $role |= Room::ROLE_OWNER;
            }

            // Create session token for the current user/connection
            $response = $room->getSessionToken($role);

            if (empty($response)) {
                return $this->errorResponse(500, self::trans('meet.session-join-error'));
            }

            $response_code = 200;
            $response['role'] = $role;
            $response['config'] = $config;
        } else {
            $response_code = 422;
            $response['code'] = 322;
        }

        return response()->json($response, $response_code);
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
