<?php

namespace App\Http\Controllers\API\V4;

use App\Domain;
use App\Http\Controllers\RelationController;
use App\Http\Resources\DomainInfoResource;
use App\Http\Resources\DomainResource;
use App\Jobs\Domain\CreateJob;
use App\Package;
use App\Rules\UserEmailDomain;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DomainsController extends RelationController
{
    /** @var string Resource localization label */
    protected $label = 'domain';

    /** @var string Resource model name */
    protected $model = Domain::class;

    /** @var array Common object properties in the API response */
    protected $objectProps = ['namespace', 'type'];

    /** @var array Resource listing order (column names) */
    protected $order = ['namespace'];

    /** @var array Resource relation method arguments */
    protected $relationArgs = [true, false];

    /**
     * Confirm domain ownership (via DNS check).
     *
     * @param int $id Domain identifier
     */
    public function confirm($id): JsonResponse
    {
        $domain = Domain::find($id);

        if (!$this->checkTenant($domain)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canRead($domain)) {
            return $this->errorResponse(403);
        }

        if (!$domain->confirm()) {
            return response()->json([
                'status' => 'error',
                'message' => self::trans('app.domain-confirm-error'),
            ]);
        }

        return response()->json([
            'status' => 'success',
            // @var array Domain status information
            'statusInfo' => self::statusInfo($domain),
            'message' => self::trans('app.domain-confirm-success'),
        ]);
    }

    /**
     * Delete a domain.
     *
     * @param string $id Domain identifier
     */
    public function destroy($id): JsonResponse
    {
        $domain = Domain::find($id);

        if (!$this->checkTenant($domain)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canDelete($domain)) {
            return $this->errorResponse(403);
        }

        // It is possible to delete domain only if there are no users/aliases/groups using it.
        if (!$domain->isEmpty()) {
            $response = ['status' => 'error', 'message' => self::trans('app.domain-notempty-error')];
            return response()->json($response, 422);
        }

        $domain->delete();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.domain-delete-success'),
        ]);
    }

    /**
     * List domains.
     *
     * The domain entitlements billed to the current user wallet(s)
     */
    public function index(): JsonResponse
    {
        $user = $this->guard()->user();

        $result = $user->domains(true, false)->orderBy('namespace')->get();

        // TODO: Searching and paging

        return response()->json([
            'status' => 'success',
            // @var string Response message
            'message' => self::trans("app.search-foundx{$this->label}s", ['x' => count($result)]),
            // List of domains
            'list' => DomainResource::collection($result),
            // @var int Number of entries in the list
            'count' => count($result),
            // @var bool Indicates that there are more entries available
            'hasMore' => false,
        ]);
    }

    /**
     * Domain information.
     *
     * @param string $id Domain identifier
     */
    public function show($id): JsonResponse
    {
        $domain = Domain::find($id);

        if (!$this->checkTenant($domain)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canRead($domain)) {
            return $this->errorResponse(403);
        }

        return (new DomainInfoResource($domain))->response();
    }

    /**
     * Create a domain.
     */
    #[BodyParameter('package', description: 'SKU package identifier', type: 'string', required: true)]
    public function store(Request $request): JsonResponse
    {
        $current_user = $this->guard()->user();
        $wallet = $current_user->wallet();

        if (!$wallet || !$wallet->isController($current_user) || !$wallet->owner) {
            return $this->errorResponse(403);
        }

        // Validate the input
        $v = Validator::make(
            $request->all(),
            [
                // Domain namespace
                'namespace' => ['required', 'string', new UserEmailDomain()],
            ]
        );

        if ($v->fails()) {
            return response()->json(['status' => 'error', 'errors' => $v->errors()], 422);
        }

        $namespace = \strtolower(request()->input('namespace'));

        // Domain already exists
        if ($domain = Domain::withTrashed()->where('namespace', $namespace)->first()) {
            // Check if the domain is soft-deleted and belongs to the same user
            $deleteBeforeCreate = $domain->trashed() && ($domain_wallet = $domain->wallet())
                && $domain_wallet->id == $wallet->id;

            if (!$deleteBeforeCreate) {
                $errors = ['namespace' => self::trans('validation.domainnotavailable')];
                return response()->json(['status' => 'error', 'errors' => $errors], 422);
            }
        }

        if (
            empty($request->package)
            || !($package = Package::withObjectTenantContext($wallet->owner)->find($request->package))
        ) {
            $errors = ['package' => self::trans('validation.packagerequired')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        if (!$package->isDomain()) {
            $errors = ['package' => self::trans('validation.packageinvalid')];
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        DB::beginTransaction();

        // Force-delete the existing domain if it is soft-deleted and belongs to the same user
        if (!empty($deleteBeforeCreate)) {
            $domain->forceDelete();
        }

        // Create the domain
        $domain = Domain::create([
            'namespace' => $namespace,
            'type' => Domain::TYPE_EXTERNAL,
        ]);

        $domain->assignPackage($package, $wallet->owner);

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.domain-create-success'),
        ]);
    }

    /**
     * Domain status (extended) information.
     *
     * @param Domain $domain Domain object
     *
     * @return array Status information
     */
    public static function statusInfo($domain): array
    {
        // If that is not a public domain, add domain specific steps
        return self::processStateInfo(
            $domain,
            [
                'domain-new' => true,
                'domain-ldap-ready' => $domain->isLdapReady(),
                'domain-verified' => $domain->isVerified(),
                'domain-confirmed' => [$domain->isConfirmed(), "/domain/{$domain->id}"],
            ]
        );
    }

    /**
     * Execute (synchronously) specified step in a domain setup process.
     *
     * @param Domain $domain Domain object
     * @param string $step   Step identifier (as in self::statusInfo())
     *
     * @return bool|null True if the execution succeeded, False if not, Null when
     *                   the job has been sent to the worker (result unknown)
     */
    public static function execProcessStep(Domain $domain, string $step): ?bool
    {
        try {
            switch ($step) {
                case 'domain-ldap-ready':
                    // Use worker to do the job
                    CreateJob::dispatch($domain->id);
                    return null;
                case 'domain-verified':
                    // Domain existence not verified
                    $domain->verify();
                    return $domain->isVerified();
                case 'domain-confirmed':
                    // Domain ownership confirmation
                    $domain->confirm();
                    return $domain->isConfirmed();
            }
        } catch (\Exception $e) {
            \Log::error($e);
        }

        return false;
    }
}
