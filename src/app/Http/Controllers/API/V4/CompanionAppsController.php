<?php

namespace App\Http\Controllers\API\V4;

use App\CompanionApp;
use App\Http\Controllers\ResourceController;
use App\Http\Resources\CompanionAppResource;
use App\Utils;
use BaconQrCode;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class CompanionAppsController extends ResourceController
{
    /**
     * Remove a companion app.
     *
     * @param string $id Companion app identifier
     */
    public function destroy($id): JsonResponse
    {
        $companion = CompanionApp::find($id);
        if (!$companion) {
            return $this->errorResponse(404);
        }

        $user = $this->guard()->user();
        if ($user->id != $companion->user_id) {
            return $this->errorResponse(403);
        }

        // Revoke client and tokens
        $client = $companion->passportClient();
        if ($client) {
            $clientRepository = app(ClientRepository::class);
            $clientRepository->delete($client);
        }

        $companion->delete();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.companion-delete-success'),
        ]);
    }

    /**
     * Create a companion app.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        $v = Validator::make(
            $request->all(),
            [
                'name' => 'required|string|max:512',
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $app = CompanionApp::create([
            'name' => $request->name,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.companion-create-success'),
            // Companion app identifier
            'id' => $app->id,
        ]);
    }

    /**
     * Register a companion app.
     */
    public function register(Request $request): JsonResponse
    {
        $user = $this->guard()->user();

        $v = Validator::make(
            $request->all(),
            [
                'notificationToken' => 'required|string|min:4|max:512',
                'deviceId' => 'required|string|min:4|max:64',
                'companionId' => 'required|max:64',
                'name' => 'required|string|max:512',
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $notificationToken = $request->notificationToken;
        $deviceId = $request->deviceId;
        $companionId = $request->companionId;
        $name = $request->name;

        \Log::info("Registering app. Notification token: {$notificationToken} Device id: {$deviceId} Name: {$name}");

        $app = CompanionApp::find($companionId);
        if (!$app) {
            return $this->errorResponse(404);
        }

        if ($app->user_id != $user->id) {
            \Log::warning("User mismatch on device registration. Expected {$user->id} but found {$app->user_id}");
            return $this->errorResponse(403);
        }

        $app->device_id = $deviceId;
        $app->mfa_enabled = true;
        $app->name = $name;
        $app->notification_token = $notificationToken;
        $app->save();

        return response()->json(['status' => 'success']);
    }

    /**
     * Generate a QR-code image for a string
     *
     * @param string $data data to encode
     *
     * @return string
     */
    private static function generateQRCode($data)
    {
        $renderer_style = new BaconQrCode\Renderer\RendererStyle\RendererStyle(300, 1);
        $renderer_image = new BaconQrCode\Renderer\Image\SvgImageBackEnd();
        $renderer = new BaconQrCode\Renderer\ImageRenderer($renderer_style, $renderer_image);
        $writer = new BaconQrCode\Writer($renderer);

        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($data));
    }

    /**
     * List companion apps.
     */
    #[QueryParameter('page', description: 'Page number', type: 'int', default: 1)]
    public function index(): JsonResponse
    {
        $user = $this->guard()->user();
        $page = (int) (request()->input('page')) ?: 1;
        $pageSize = 20;
        $hasMore = false;

        $result = CompanionApp::where('user_id', $user->id);

        $result = $result->orderBy('created_at')
            ->limit($pageSize + 1)
            ->offset($pageSize * ($page - 1))
            ->get();

        if (count($result) > $pageSize) {
            $result->pop();
            $hasMore = true;
        }

        $result = [
            // List of companion apps
            'list' => CompanionAppResource::collection($result),
            // @var int Number of entries in the list
            'count' => count($result),
            // @var bool Indicates that there are more entries available
            'hasMore' => $hasMore,
        ];

        return response()->json($result);
    }

    /**
     * Get companion app information.
     *
     * @param string $id Companion app identifier
     */
    public function show($id): CompanionAppResource|JsonResponse
    {
        $result = CompanionApp::find($id);
        if (!$result) {
            return $this->errorResponse(404);
        }

        $user = $this->guard()->user();
        if ($user->id != $result->user_id) {
            return $this->errorResponse(403);
        }

        return new CompanionAppResource($result);
    }

    /**
     * Retrieve the pairing information encoded into a QR-code image.
     *
     * @param string $id Companion app identifier
     */
    public function pairing($id): JsonResponse
    {
        $result = CompanionApp::find($id);
        if (!$result) {
            return $this->errorResponse(404);
        }

        $user = $this->guard()->user();
        if ($user->id != $result->user_id) {
            return $this->errorResponse(403);
        }

        $client = $result->passportClient();
        if (!$client) {
            $client = Passport::client()->forceFill([
                'user_id' => $user->id,
                'name' => "CompanionApp Password Grant Client",
                'secret' => Str::random(40),
                'provider' => 'users',
                'redirect' => 'https://' . \config('app.website_domain'),
                'personal_access_client' => 0,
                'password_client' => 1,
                'revoked' => false,
                'allowed_scopes' => ["mfa", "fs"],
            ]);
            $client->save();

            $result->setPassportClient($client);
            $result->save();
        }

        $response = [
            // Server URL
            'serverUrl' => Utils::serviceUrl('', $user->tenant_id),
            // Passport client identifier
            'clientIdentifier' => $client->id,
            // Client secret
            'clientSecret' => $client->secret,
            // Companion app identifier
            'companionId' => $id,
            // User email address
            'username' => $user->email,
        ];

        // TODO: Make it visible in API Docs
        $response['qrcode'] = self::generateQRCode(json_encode($response));

        return response()->json($response);
    }
}
