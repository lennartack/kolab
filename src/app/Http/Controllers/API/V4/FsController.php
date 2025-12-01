<?php

namespace App\Http\Controllers\API\V4;

use App\Fs\Item;
use App\Fs\Property;
use App\Http\Controllers\RelationController;
use App\Rules\FileName;
use App\Support\Facades\Storage;
use App\Utils;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FsController extends RelationController
{
    protected const READ = 'r';
    protected const WRITE = 'w';

    protected const TYPE_COLLECTION = 'collection';
    protected const TYPE_FILE = 'file';
    protected const TYPE_UNKNOWN = 'unknown';

    /** @var string Resource localization label */
    protected $label = 'file';

    /** @var string Resource model name */
    protected $model = Item::class;

    /**
     * Delete a file.
     *
     * @param string $id File identifier
     */
    public function destroy($id): JsonResponse
    {
        // Only the file owner can do that, for now
        $file = $this->inputItem($id, null);

        if (is_int($file)) {
            return $this->errorResponse($file);
        }

        // Here we're just marking the file as deleted, it will be removed from the
        // storage later with the fs:expunge command
        $file->delete();

        if ($file->isCollection()) {
            $message = self::trans('app.collection-delete-success');
        }

        return response()->json([
            'status' => 'success',
            'message' => $message ?? self::trans('app.file-delete-success'),
        ]);
    }

    /**
     * Fetch content of a file.
     *
     * @param string $id the download (not file) identifier
     *
     * @return Response|StreamedResponse
     */
    public function download($id)
    {
        $fileId = Cache::get('download:' . $id);

        if (!$fileId) {
            return response('Not found', 404);
        }

        $file = Item::find($fileId);

        if (!$file) {
            return response('Not found', 404);
        }

        return Storage::fileDownload($file);
    }

    /**
     * Fetch the permissions for the specific file.
     *
     * @param string $fileId the file identifier
     *
     * @return JsonResponse
     */
    public function getPermissions($fileId)
    {
        // Only the file owner can do that, for now
        $file = $this->inputItem($fileId, null);

        if (is_int($file)) {
            return $this->errorResponse($file);
        }

        $result = $file->properties()->whereLike('key', 'share-%')->get()->map(
            static fn ($prop) => self::permissionToClient($prop->key, $prop->value)
        );

        $result = [
            'list' => $result,
            'count' => count($result),
        ];

        return response()->json($result);
    }

    /**
     * Add permission for the specific file.
     *
     * @param string $fileId the file identifier
     *
     * @return JsonResponse
     */
    public function createPermission($fileId)
    {
        // Only the file owner can do that, for now
        $file = $this->inputItem($fileId, null);

        if (is_int($file)) {
            return $this->errorResponse($file);
        }

        // Validate/format input
        $v = Validator::make(request()->all(), [
            'user' => 'email|required',
            'permissions' => 'string|required',
        ]);

        $errors = $v->fails() ? $v->errors()->toArray() : [];

        $acl = self::inputAcl(request()->input('permissions'));

        if (empty($errors['permissions']) && empty($acl)) {
            $errors['permissions'] = self::trans('validation.file-perm-invalid');
        }

        $user = \strtolower(request()->input('user'));

        // Check if it already exists
        if (empty($errors['user'])) {
            if ($file->properties()->whereLike('key', 'share-%')->whereLike('value', "{$user}:%")->exists()) {
                $errors['user'] = self::trans('validation.file-perm-exists');
            }
        }

        if (!empty($errors)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // Create the property (with a unique id)
        while ($shareId = 'share-' . Utils::uuidStr()) {
            if (!Property::where('key', $shareId)->exists()) {
                break;
            }
        }

        $file->setProperty($shareId, "{$user}:{$acl}");

        $result = self::permissionToClient($shareId, "{$user}:{$acl}");

        return response()->json($result + [
            'status' => 'success',
            'message' => self::trans('app.file-permissions-create-success'),
        ]);
    }

    /**
     * Delete file permission.
     *
     * @param string $fileId the file identifier
     * @param string $id     the file permission identifier
     *
     * @return JsonResponse
     */
    public function deletePermission($fileId, $id)
    {
        // Only the file owner can do that, for now
        $file = $this->inputItem($fileId, null);

        if (is_int($file)) {
            return $this->errorResponse($file);
        }

        $property = $file->properties()->where('key', $id)->first();

        if (!$property) {
            return $this->errorResponse(404);
        }

        $property->delete();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.file-permissions-delete-success'),
        ]);
    }

    /**
     * Update file permission.
     *
     * @param Request $request the API request
     * @param string  $fileId  the file identifier
     * @param string  $id      the file permission identifier
     *
     * @return JsonResponse
     */
    public function updatePermission(Request $request, $fileId, $id)
    {
        // Only the file owner can do that, for now
        $file = $this->inputItem($fileId, null);

        if (is_int($file)) {
            return $this->errorResponse($file);
        }

        $property = $file->properties()->where('key', $id)->first();

        if (!$property) {
            return $this->errorResponse(404);
        }

        // Validate/format input
        $v = Validator::make($request->all(), [
            'user' => 'email|required',
            'permissions' => 'string|required',
        ]);

        $errors = $v->fails() ? $v->errors()->toArray() : [];

        $acl = self::inputAcl($request->input('permissions'));

        if (empty($errors['permissions']) && empty($acl)) {
            $errors['permissions'] = self::trans('validation.file-perm-invalid');
        }

        $user = \strtolower($request->input('user'));

        if (empty($errors['user']) && !str_starts_with($property->value, "{$user}:")) {
            if ($file->properties()->whereLike('key', 'share-%')->whereLike('value', "{$user}:%")->exists()) {
                $errors['user'] = self::trans('validation.file-perm-exists');
            }
        }

        if (!empty($errors)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        $property->value = "{$user}:{$acl}";
        $property->save();

        $result = self::permissionToClient($property->key, $property->value);

        return response()->json($result + [
            'status' => 'success',
            'message' => self::trans('app.file-permissions-update-success'),
        ]);
    }

    /**
     * Listing of files (and folders).
     */
    public function index(): JsonResponse
    {
        $search = trim(request()->input('search'));
        $page = (int) (request()->input('page')) ?: 1;
        $parent = request()->input('parent');
        $type = request()->input('type');
        $pageSize = 100;
        $hasMore = false;

        $user = $this->guard()->user();

        $result = $user->fsItems()->select('fs_items.*', 'fs_properties.value as name');

        if ($parent) {
            $result->join('fs_relations', 'fs_items.id', '=', 'fs_relations.related_id')
                ->where('fs_relations.item_id', $parent);
        } else {
            $result->leftJoin('fs_relations', 'fs_items.id', '=', 'fs_relations.related_id')
                ->whereNull('fs_relations.related_id');
        }

        // Add properties
        $result->join('fs_properties', 'fs_items.id', '=', 'fs_properties.item_id')
            ->whereNot('type', '&', Item::TYPE_INCOMPLETE)
            ->where('key', 'name');

        if ($type) {
            if ($type == self::TYPE_COLLECTION) {
                $result->where('type', '&', Item::TYPE_COLLECTION);
            } else {
                $result->where('type', '&', Item::TYPE_FILE);
            }
        }

        if (strlen($search)) {
            $result->whereLike('fs_properties.value', "%{$search}%");
        }

        $result = $result->orderBy('name')
            ->limit($pageSize + 1)
            ->offset($pageSize * ($page - 1))
            ->get();

        if (count($result) > $pageSize) {
            $result->pop();
            $hasMore = true;
        }

        // Process the result
        // @phpstan-ignore argument.unresolvableType
        $result = $result->map(function ($file) {
            // TODO: This is going to be 100 SELECT queries (with pageSize=100), we should get
            // file properties using the main query
            $result = $this->objectToClient($file);
            $result['name'] = $file->name; // @phpstan-ignore-line

            return $result;
        });

        $result = [
            'list' => $result,
            'count' => count($result),
            'hasMore' => $hasMore,
        ];

        return response()->json($result);
    }

    /**
     * Fetch the specific file metadata or content.
     *
     * @param string $id the file identifier
     */
    public function show($id): JsonResponse|StreamedResponse
    {
        $file = $this->inputItem($id, self::READ);

        if (is_int($file)) {
            return $this->errorResponse($file);
        }

        $response = $this->objectToClient($file, true);

        if (request()->input('downloadUrl')) {
            // Generate a download URL (that does not require authentication)
            $downloadId = Utils::uuidStr();
            Cache::add('download:' . $downloadId, $file->id, 60);
            $response['downloadUrl'] = Utils::serviceUrl('api/v4/fs/downloads/' . $downloadId);
        } elseif (request()->input('download')) {
            // Return the file content
            return Storage::fileDownload($file);
        }

        $response['mtime'] = $file->updated_at->format('Y-m-d H:i');

        // TODO: Handle read-write/full access rights
        $isOwner = $this->guard()->user()->id == $file->user_id;
        $response['canUpdate'] = $isOwner;
        $response['canDelete'] = $isOwner;
        $response['isOwner'] = $isOwner;

        return response()->json($response);
    }

    /**
     * Create a new file.
     *
     * @param Request $request the API request
     *
     * @return JsonResponse The response
     */
    public function store(Request $request)
    {
        $type = $request->input('type');
        if ($type == self::TYPE_COLLECTION) {
            return $this->createCollection($request);
        }

        // Validate file name input
        $v = Validator::make($request->all(), ['name' => ['required', new FileName()]]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $parents = $this->getInputParents($request);
        if ($errorResponse = $this->validateParents($parents)) {
            return $errorResponse;
        }

        $filename = $request->input('name');
        $media = $request->input('media');

        // TODO: Delete the existing incomplete file with the same name?
        $properties = ['name' => $filename];

        foreach ($request->all() as $key => $value) {
            if (str_starts_with($key, 'property-')) {
                $propertyKey = substr($key, 9);

                if ($errorResponse = $this->validatePropertyName($propertyKey)) {
                    return $errorResponse;
                }

                $properties[$propertyKey] = $value;
            }
        }

        DB::beginTransaction();

        $file = $this->deduplicateOrCreate($request, Item::TYPE_INCOMPLETE | Item::TYPE_FILE);
        $file->setProperties($properties);

        if (!empty($parents)) {
            $file->parents()->sync($parents);
        }

        DB::commit();

        $params = [];
        $params['mimetype'] = $request->headers->get('Content-Type', null);

        if ($media == 'resumable') {
            $params['uploadId'] = 'resumable';
            $params['size'] = $request->input('size');
            $params['from'] = $request->input('from') ?: 0;
        }

        try {
            $response = Storage::fileInput($request->getContent(true), $params, $file);

            $response['status'] = 'success';

            if (!empty($response['id'])) {
                $response += $this->objectToClient($file, true);
                $response['message'] = self::trans('app.file-create-success');
            }
        } catch (\Exception $e) {
            \Log::error($e);
            $file->delete();
            return $this->errorResponse(500);
        }

        return response()->json($response);
    }

    /**
     * Update a file.
     *
     * @param Request $request the API request
     * @param string  $id      File identifier
     *
     * @return JsonResponse The response
     */
    public function update(Request $request, $id)
    {
        $file = $this->inputItem($id, self::WRITE);

        if (is_int($file)) {
            return $this->errorResponse($file);
        }

        if ($file->isCollection()) {
            // Updating a collection is not supported yet
            return $this->errorResponse(405);
        }

        $media = $request->input('media') ?: 'metadata';

        if ($media == 'metadata') {
            $filename = $request->input('name');

            // Validate file name input
            if ($filename != $file->getProperty('name')) {
                $v = Validator::make($request->all(), ['name' => [new FileName()]]);

                if ($v->fails()) {
                    return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
                }
            }

            $parents = [
                'X-Kolab-Parents' => [],
                'X-Kolab-Add-Parents' => [],
                'X-Kolab-Remove-Parents' => [],
            ];

            // Collect and validate parents from the request headers
            foreach (array_keys($parents) as $header) {
                if ($value = $request->headers->get($header, null)) {
                    $list = explode(',', $value);
                    if ($errorResponse = $this->validateParents($list)) {
                        return $errorResponse;
                    }
                    $parents[$header] = $list;
                }
            }

            DB::beginTransaction();

            if (count($parents['X-Kolab-Parents'])) {
                $file->parents()->sync($parents['X-Kolab-Parents']);
            }
            if (count($parents['X-Kolab-Add-Parents'])) {
                $file->parents()->syncWithoutDetaching($parents['X-Kolab-Add-Parents']);
            }
            if (count($parents['X-Kolab-Remove-Parents'])) {
                $file->parents()->detach($parents['X-Kolab-Remove-Parents']);
            }

            if ($filename != $file->getProperty('name')) {
                $file->setProperty('name', $filename);
            }

            DB::commit();
        } elseif ($media == 'resumable' || $media == 'content') {
            $params = [];

            if ($media == 'resumable') {
                $params['uploadId'] = 'resumable';
                $params['size'] = $request->input('size');
                $params['from'] = $request->input('from') ?: 0;
            }

            try {
                $response = Storage::fileInput($request->getContent(true), $params, $file);
            } catch (\Exception $e) {
                \Log::error($e);
                return $this->errorResponse(500);
            }
        } else {
            $errors = ['media' => self::trans('validation.entryinvalid', ['attribute' => 'media'])];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        $response['status'] = 'success';

        if ($media == 'metadata' || !empty($response['id'])) {
            $response += $this->objectToClient($file, true);
            $response['message'] = self::trans('app.file-update-success');
        }

        return response()->json($response);
    }

    /**
     * Upload a file content.
     *
     * @param Request $request the API request
     * @param string  $id      Upload (not file) identifier
     *
     * @return JsonResponse The response
     */
    public function upload(Request $request, $id)
    {
        $params = [
            'uploadId' => $id,
            'from' => $request->input('from') ?: 0,
        ];

        try {
            $response = Storage::fileInput($request->getContent(true), $params);

            $response['status'] = 'success';

            if (!empty($response['id'])) {
                $response += $this->objectToClient(Item::find($response['id']), true);
                $response['message'] = self::trans('app.file-upload-success');
            }
        } catch (\Exception $e) {
            \Log::error($e);
            return $this->errorResponse(500);
        }

        return response()->json($response);
    }

    /**
     * Create a new collection.
     *
     * @param Request $request the API request
     *
     * @return JsonResponse The response
     */
    protected function createCollection(Request $request)
    {
        // Validate file name input
        $v = Validator::make($request->all(), [
            'name' => ['required', new FileName()],
            'deviceId' => ['max:255'],
            'collectionType' => ['max:255'],
        ]);

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $parents = $this->getInputParents($request);
        if ($errorResponse = $this->validateParents($parents)) {
            return $errorResponse;
        }

        $properties = [
            'name' => $request->input('name'),
            'deviceId' => $request->input('deviceId'),
            'collectionType' => $request->input('collectionType'),
        ];

        foreach ($request->all() as $key => $value) {
            if (str_starts_with($key, 'property-')) {
                $propertyKey = substr($key, 9);

                if ($errorResponse = $this->validatePropertyName($propertyKey)) {
                    return $errorResponse;
                }

                $properties[$propertyKey] = $value;
            }
        }

        DB::beginTransaction();

        $item = $this->deduplicateOrCreate($request, Item::TYPE_COLLECTION);
        $item->setProperties($properties);

        if (!empty($parents)) {
            $item->parents()->sync($parents);
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'id' => $item->id,
            'message' => self::trans('app.collection-create-success'),
        ]);
    }

    /**
     * Find or create an item, using deduplicate parameters
     */
    protected function deduplicateOrCreate(Request $request, $type): Item
    {
        $user = $this->guard()->user();
        $item = null;

        if ($request->has('deduplicate-property')) {
            // query for item by deduplicate-value
            $item = $user->fsItems()->select('fs_items.*')
                ->join('fs_properties', static function ($join) use ($request) {
                    $join->on('fs_items.id', '=', 'fs_properties.item_id')
                        ->where('fs_properties.key', $request->input('deduplicate-property'));
                })
                ->where('type', '&', $type)
                ->whereLike('fs_properties.value', '%' . $request->input('deduplicate-value') . '%')
                ->first();

            // FIXME: Should we throw an error if there's more than one item?
        }

        if (!$item) {
            $item = $user->fsItems()->create(['type' => $type]);
        }

        return $item;
    }

    /**
     * Convert Permission to an array for the API response.
     *
     * @param string $id    Permission identifier
     * @param string $value Permission record
     *
     * @return array Permission data
     */
    protected static function permissionToClient(string $id, string $value): array
    {
        [$user, $acl] = explode(':', $value);

        $perms = str_contains($acl, self::WRITE) ? 'read-write' : 'read-only';

        return [
            'id' => $id,
            'user' => $user,
            'permissions' => $perms,
            'link' => Utils::serviceUrl('file/' . $id),
        ];
    }

    /**
     * Convert ACL label into internal permissions spec.
     *
     * @param string $acl Access rights label
     *
     * @return ?string Permissions ('r' or 'rw')
     */
    protected static function inputAcl($acl): ?string
    {
        // The ACL widget supports 'full', 'read-write', 'read-only',
        if ($acl == 'read-write') {
            return self::READ . self::WRITE;
        }

        if ($acl == 'read-only') {
            return self::READ;
        }

        return null;
    }

    /**
     * Get the input file object, check permissions
     *
     * @param string  $fileId     File or file permission identifier
     * @param ?string $permission Required access rights
     *
     * @return Item|int File object or error code
     */
    protected function inputItem($fileId, $permission)
    {
        $user = $this->guard()->user();
        $isShare = str_starts_with($fileId, 'share-');

        // Access via file permission identifier
        if ($isShare) {
            $property = Property::where('key', $fileId)->first();

            if (!$property) {
                return 404;
            }

            [$acl_user, $acl] = explode(':', $property->value);

            if (!$permission || $acl_user != $user->email || !str_contains($acl, $permission)) {
                return 403;
            }

            $fileId = $property->item_id;
        }

        $file = Item::find($fileId);

        if (!$file || $file->isIncomplete()) {
            return 404;
        }

        if (!$isShare && $user->id != $file->user_id) {
            return 403;
        }

        return $file;
    }

    /**
     * Prepare a file object for the UI.
     *
     * @param object $object An object
     * @param bool   $full   Include all object properties
     *
     * @return array Object information
     */
    protected function objectToClient($object, bool $full = false): array
    {
        $result = ['id' => $object->id];
        if ($object->isCollection()) {
            $result['type'] = self::TYPE_COLLECTION;
        } elseif ($object->isFile()) {
            $result['type'] = self::TYPE_FILE;
        } else {
            $result['type'] = self::TYPE_UNKNOWN;
        }

        if ($full) {
            $props = array_filter($object->getProperties(['name', 'size', 'mimetype']));

            // convert size to int and make sure the property exists
            $props['size'] = (int) ($props['size'] ?? 0);
            $result += $props;
        }

        $result['updated_at'] = $object->updated_at->toDateTimeString();
        $result['created_at'] = $object->created_at->toDateTimeString();

        return $result;
    }

    /**
     * Validate parents list
     */
    protected function validateParents($parents)
    {
        $user = $this->guard()->user();
        if (!empty($parents) && count($parents) != $user->fsItems()->whereIn('id', $parents)->count()) {
            $error = self::trans('validation.fsparentunknown');
            return response()->json(['status' => 'error', 'errors' => [$error]], 422);
        }

        return null;
    }

    /**
     * Collect collection Ids from input
     */
    protected function getInputParents(Request $request): array
    {
        $parents = [];

        if ($parentHeader = $request->headers->get('X-Kolab-Parents')) {
            $parents = explode(',', $parentHeader);
        }

        if ($parent = $request->input('parent')) {
            $parents = array_merge($parents, [$parent]);
        }

        return array_values(array_unique($parents));
    }

    /**
     * Validate property name
     */
    protected function validatePropertyName(string $name)
    {
        if (strlen($name) > 191) {
            $error = self::trans('validation.max.string', ['attribute' => $name, 'max' => 191]);
            return response()->json(['status' => 'error', 'errors' => [$error]], 422);
        }

        if (preg_match('/^(name)$/i', $name)) {
            $error = self::trans('validation.prohibited', ['attribute' => $name]);
            return response()->json(['status' => 'error', 'errors' => [$error]], 422);
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            $error = self::trans('validation.regex_format', ['attribute' => $name, 'format' => 'a-zA-Z0-9_-']);
            return response()->json(['status' => 'error', 'errors' => [$error]], 422);
        }

        return null;
    }
}
