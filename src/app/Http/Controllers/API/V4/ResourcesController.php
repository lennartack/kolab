<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\RelationController;
use App\Http\Resources\ResourceInfoResource;
use App\Http\Resources\ResourceResource;
use App\Jobs\Resource\CreateJob;
use App\Resource;
use App\Rules\ResourceName;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ResourcesController extends RelationController
{
    /** @var string Resource localization label */
    protected $label = 'resource';

    /** @var string Resource model name */
    protected $model = Resource::class;

    /** @var array Resource listing order (column names) */
    protected $order = ['name'];

    /** @var array Common object properties in the API response */
    protected $objectProps = ['email', 'name'];

    /**
     * List resources.
     *
     * The resource entitlements billed to the current user wallet(s)
     */
    public function index(): JsonResponse
    {
        $user = $this->guard()->user();

        $result = $user->resources()->orderBy('name')->get();

        // TODO: Searching and paging

        return response()->json([
            'status' => 'success',
            // @var string Response message
            'message' => self::trans("app.search-foundxresources", ['x' => count($result)]),
            // List of resources
            'list' => ResourceResource::collection($result),
            // @var int Number of entries in the list
            'count' => count($result),
            // @var bool Indicates that there are more entries available
            'hasMore' => false,
        ]);
    }

    /**
     * Resource information.
     *
     * @param string $id Resource identifier
     */
    public function show($id): JsonResponse
    {
        $resource = Resource::find($id);

        if (!$this->checkTenant($resource)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canRead($resource)) {
            return $this->errorResponse(403);
        }

        return (new ResourceInfoResource($resource))->response();
    }

    /**
     * Resource status (extended) information
     *
     * @param Resource $resource Resource object
     *
     * @return array Status information
     */
    public static function statusInfo($resource): array
    {
        return self::processStateInfo(
            $resource,
            [
                'resource-new' => true,
                'resource-ldap-ready' => $resource->isLdapReady(),
                'resource-imap-ready' => $resource->isImapReady(),
            ]
        );
    }

    /**
     * Create a new resource.
     *
     * @param Request $request the API request
     */
    #[BodyParameter('domain', description: 'Domain namespace', type: 'string', required: true)]
    public function store(Request $request): JsonResponse
    {
        $current_user = $this->guard()->user();
        $wallet = $current_user->wallet();

        if (!$wallet || !$wallet->isController($current_user) || !$wallet->owner) {
            return $this->errorResponse(403);
        }

        $domain = request()->input('domain');

        $v = Validator::make(
            $request->all(),
            [
                // Resource name
                'name' => ['required', 'string', new ResourceName($wallet->owner, $domain)],
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        DB::beginTransaction();

        // Create the resource
        $resource = new Resource();
        $resource->name = request()->input('name');
        $resource->domainName = $domain;
        $resource->save();

        $resource->assignToWallet($wallet);

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.resource-create-success'),
        ]);
    }

    /**
     * Update a resource.
     *
     * @param Request $request the API request
     * @param string  $id      Resource identifier
     */
    #[BodyParameter('name', description: 'Resource name', type: 'string')]
    public function update(Request $request, $id): JsonResponse
    {
        $resource = Resource::find($id);

        if (!$this->checkTenant($resource)) {
            return $this->errorResponse(404);
        }

        $current_user = $this->guard()->user();

        if (!$current_user->canUpdate($resource)) {
            return $this->errorResponse(403);
        }

        $owner = $resource->wallet()->owner;

        $name = $request->input('name');
        $errors = [];

        // Validate the resource name
        if ($name !== null && $name != $resource->name) {
            $domainName = explode('@', $resource->email, 2)[1];
            $rules = ['name' => ['required', 'string', new ResourceName($owner, $domainName)]];

            $v = Validator::make($request->all(), $rules);

            if ($v->fails()) {
                $errors = $v->errors()->toArray();
            } else {
                $resource->name = $name;
            }
        }

        if (!empty($errors)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // SkusController::updateEntitlements($resource, $request->skus);

        $resource->save();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.resource-update-success'),
        ]);
    }

    /**
     * Execute (synchronously) specified step in a resource setup process.
     *
     * @param Resource $resource Resource object
     * @param string   $step     Step identifier (as in self::statusInfo())
     *
     * @return bool|null True if the execution succeeded, False if not, Null when
     *                   the job has been sent to the worker (result unknown)
     */
    public static function execProcessStep(Resource $resource, string $step): ?bool
    {
        try {
            if (str_starts_with($step, 'domain-')) {
                return DomainsController::execProcessStep($resource->domain(), $step);
            }

            switch ($step) {
                case 'resource-ldap-ready':
                case 'resource-imap-ready':
                    // Use worker to do the job, frontend might not have the IMAP admin credentials
                    CreateJob::dispatch($resource->id);
                    return null;
            }
        } catch (\Exception $e) {
            \Log::error($e);
        }

        return false;
    }
}
