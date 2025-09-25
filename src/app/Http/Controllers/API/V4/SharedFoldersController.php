<?php

namespace App\Http\Controllers\API\V4;

use App\Http\Controllers\RelationController;
use App\Http\Resources\SharedFolderInfoResource;
use App\Http\Resources\SharedFolderResource;
use App\Jobs\SharedFolder\CreateJob;
use App\Rules\SharedFolderName;
use App\Rules\SharedFolderType;
use App\SharedFolder;
use App\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SharedFoldersController extends RelationController
{
    /** @var string Resource localization label */
    protected $label = 'shared-folder';

    /** @var string Resource model name */
    protected $model = SharedFolder::class;

    /** @var array Resource listing order (column names) */
    protected $order = ['name'];

    /** @var array Common object properties in the API response */
    protected $objectProps = ['email', 'name', 'type'];

    /**
     * List shared folders.
     *
     * The shared folder entitlements billed to the current user wallet(s)
     */
    public function index(): JsonResponse
    {
        $user = $this->guard()->user();

        $result = $user->sharedFolders()->orderBy('name')->get();

        // TODO: Searching and paging

        return response()->json([
            'status' => 'success',
            // @var string Response message
            'message' => self::trans("app.search-foundxshared-folders", ['x' => count($result)]),
            // List of shared folders
            'list' => SharedFolderResource::collection($result),
            // @var int Number of entries in the list
            'count' => count($result),
            // @var bool Indicates that there are more entries available
            'hasMore' => false,
        ]);
    }

    /**
     * Shared folder information.
     *
     * @param string $id Shared folder identifier
     */
    public function show($id): JsonResponse
    {
        $folder = SharedFolder::find($id);

        if (!$this->checkTenant($folder)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canRead($folder)) {
            return $this->errorResponse(403);
        }

        return (new SharedFolderInfoResource($folder))->response();
    }

    /**
     * SharedFolder status (extended) information
     *
     * @param SharedFolder $folder SharedFolder object
     *
     * @return array Status information
     */
    public static function statusInfo($folder): array
    {
        return self::processStateInfo(
            $folder,
            [
                'shared-folder-new' => true,
                'shared-folder-ldap-ready' => $folder->isLdapReady(),
                'shared-folder-imap-ready' => $folder->isImapReady(),
            ]
        );
    }

    /**
     * Create a shared folder.
     */
    #[BodyParameter('domain', description: 'Domain namespace', type: 'string', required: true)]
    #[BodyParameter('name', description: 'Folder name', type: 'string', required: true)]
    #[BodyParameter('type', description: 'Folder type', type: 'string', required: true)]
    public function store(Request $request): JsonResponse
    {
        $current_user = $this->guard()->user();
        $wallet = $current_user->wallet();

        if (!$wallet || !$wallet->isController($current_user) || !$wallet->owner) {
            return $this->errorResponse(403);
        }

        if ($errors = $this->validateFolderRequest($request, null, $wallet->owner)) {
            return response()->json(['status' => 'error', 'errors' => /* @var array */ $errors], 422);
        }

        DB::beginTransaction();

        // Create the shared folder
        $folder = new SharedFolder();
        $folder->name = $request->input('name');
        $folder->type = $request->input('type');
        $folder->domainName = $request->input('domain');
        $folder->save();

        if (!empty($request->aliases) && $folder->type === 'mail') {
            $folder->setAliases($request->aliases);
        }

        $folder->assignToWallet($wallet);

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.shared-folder-create-success'),
        ]);
    }

    /**
     * Update a shared folder.
     *
     * @param Request $request the API request
     * @param string  $id      Shared folder identifier
     */
    #[BodyParameter('name', description: 'Folder name', type: 'string')]
    #[BodyParameter('aliases', description: 'Folder email aliases', type: 'array<string>')]
    public function update(Request $request, $id): JsonResponse
    {
        $folder = SharedFolder::find($id);

        if (!$this->checkTenant($folder)) {
            return $this->errorResponse(404);
        }

        $current_user = $this->guard()->user();

        if (!$current_user->canUpdate($folder)) {
            return $this->errorResponse(403);
        }

        if ($errors = $this->validateFolderRequest($request, $folder, $folder->walletOwner())) {
            return response()->json(['status' => 'error', 'errors' => /* @var array */ $errors], 422);
        }

        $name = $request->input('name');

        DB::beginTransaction();

        // SkusController::updateEntitlements($folder, $request->skus);

        if ($name && $name != $folder->name) {
            $folder->name = $name;
        }

        $folder->save();

        if (isset($request->aliases) && $folder->type === 'mail') {
            $folder->setAliases($request->aliases);
        }

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.shared-folder-update-success'),
        ]);
    }

    /**
     * Execute (synchronously) specified step in a shared folder setup process.
     *
     * @param SharedFolder $folder Shared folder object
     * @param string       $step   Step identifier (as in self::statusInfo())
     *
     * @return bool|null True if the execution succeeded, False if not, Null when
     *                   the job has been sent to the worker (result unknown)
     */
    public static function execProcessStep(SharedFolder $folder, string $step): ?bool
    {
        try {
            if (str_starts_with($step, 'domain-')) {
                return DomainsController::execProcessStep($folder->domain(), $step);
            }

            switch ($step) {
                case 'shared-folder-ldap-ready':
                case 'shared-folder-imap-ready':
                    // Use worker to do the job, frontend might not have the IMAP admin credentials
                    CreateJob::dispatch($folder->id);
                    return null;
            }
        } catch (\Exception $e) {
            \Log::error($e);
        }

        return false;
    }

    /**
     * Validate shared folder input
     *
     * @param Request           $request the API request
     * @param SharedFolder|null $folder  Shared folder
     * @param User|null         $owner   Account owner
     *
     * @return ?array List of validation errors if any
     */
    protected function validateFolderRequest(Request $request, $folder, $owner): ?array
    {
        $errors = [];

        if (empty($folder)) {
            $name = $request->input('name');
            $domain = $request->input('domain');
            $rules = [
                'name' => ['required', 'string', new SharedFolderName($owner, $domain)],
                'type' => ['required', 'string', new SharedFolderType()],
            ];
        } else {
            // On update validate the folder name (if changed)
            $name = $request->input('name');
            $domain = explode('@', $folder->email, 2)[1];

            if ($name !== null && $name != $folder->name) {
                $rules = ['name' => ['required', 'string', new SharedFolderName($owner, $domain)]];
            }
        }

        if (!empty($rules)) {
            $v = Validator::make($request->all(), $rules);

            if ($v->fails()) {
                $errors = $v->errors()->toArray();
            }
        }

        // Validate aliases input
        if (isset($request->aliases)) {
            $aliases = [];
            $existing_aliases = $owner->aliases()->get()->pluck('alias')->toArray();

            foreach ($request->aliases as $idx => $alias) {
                if (is_string($alias) && !empty($alias)) {
                    // Alias cannot be the same as the email address
                    if (!empty($folder) && Str::lower($alias) == Str::lower($folder->email)) {
                        continue;
                    }

                    // validate new aliases
                    if (
                        !in_array($alias, $existing_aliases)
                        && ($error = self::validateAlias($alias, $owner, $name, $domain))
                    ) {
                        if (!isset($errors['aliases'])) {
                            $errors['aliases'] = [];
                        }
                        $errors['aliases'][$idx] = $error;
                        continue;
                    }

                    $aliases[] = $alias;
                }
            }

            $request->aliases = $aliases;
        }

        return !empty($errors) ? $errors : null;
    }

    /**
     * Email address validation for use as a shared folder alias.
     *
     * @param string $alias      Email address
     * @param User   $owner      The account owner
     * @param string $folderName Folder name
     * @param string $domain     Folder domain
     *
     * @return ?string Error message on validation error
     */
    public static function validateAlias(string $alias, User $owner, string $folderName, string $domain): ?string
    {
        $lmtp_alias = "shared+shared/{$folderName}@{$domain}";

        if ($alias === $lmtp_alias) {
            return null;
        }

        return UsersController::validateAlias($alias, $owner);
    }
}
