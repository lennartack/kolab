<?php

namespace App\Http\Controllers\API\V4;

use App\Domain;
use App\Group;
use App\Http\Controllers\RelationController;
use App\Http\Resources\GroupInfoResource;
use App\Http\Resources\GroupResource;
use App\Jobs\Group\CreateJob;
use App\Rules\ExternalEmail;
use App\Rules\GroupName;
use App\Rules\UserEmailLocal;
use App\User;
use Dedoc\Scramble\Attributes\BodyParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GroupsController extends RelationController
{
    /** @var string Resource localization label */
    protected $label = 'distlist';

    /** @var string Resource model name */
    protected $model = Group::class;

    /** @var array Resource listing order (column names) */
    protected $order = ['name', 'email'];

    /** @var array Common object properties in the API response */
    protected $objectProps = ['email', 'name'];

    /**
     * On group create it is filled with a user or group object to force-delete
     * before the creation of a new group record is possible.
     *
     * @var User|Group|null
     */
    protected $deleteBeforeCreate;

    /**
     * List groups.
     *
     * The group entitlements billed to the current user wallet(s)
     */
    public function index(): JsonResponse
    {
        $user = $this->guard()->user();

        $result = $user->groups()->orderBy('name')->orderBy('email')->get();

        // TODO: Searching and paging

        return response()->json([
            'status' => 'success',
            // @var string Response message
            'message' => self::trans("app.search-foundxdistlists", ['x' => count($result)]),
            // List of groups
            'list' => GroupResource::collection($result),
            // @var int Number of entries in the list
            'count' => count($result),
            // @var bool Indicates that there are more entries available
            'hasMore' => false,
        ]);
    }

    /**
     * Group information.
     *
     * @param string $id Group identifier
     */
    public function show($id): JsonResponse
    {
        $group = Group::find($id);

        if (!$this->checkTenant($group)) {
            return $this->errorResponse(404);
        }

        if (!$this->guard()->user()->canRead($group)) {
            return $this->errorResponse(403);
        }

        return (new GroupInfoResource($group))->response();
    }

    /**
     * Group status (extended) information
     *
     * @param Group $group Group object
     *
     * @return array Status information
     */
    public static function statusInfo($group): array
    {
        return self::processStateInfo(
            $group,
            [
                'distlist-new' => true,
                'distlist-ldap-ready' => $group->isLdapReady(),
            ]
        );
    }

    /**
     * Create a group.
     */
    #[BodyParameter('name', description: 'Group name', type: 'string', required: true)]
    #[BodyParameter('email', description: 'Group email address', type: 'string', required: true)]
    #[BodyParameter('members', description: 'Member email addresses', type: 'array<string>', required: true)]
    public function store(Request $request): JsonResponse
    {
        $current_user = $this->guard()->user();
        $wallet = $current_user->wallet();

        if (!$wallet || !$wallet->isController($current_user) || !$wallet->owner) {
            return $this->errorResponse(403);
        }

        $email = $request->input('email');
        $members = $request->input('members');
        $errors = [];
        $rules = [
            'name' => 'required|string|max:191',
        ];

        $this->deleteBeforeCreate = null;

        // Validate group address
        if ($error = self::validateGroupEmail($email, $wallet->owner, $this->deleteBeforeCreate)) {
            $errors['email'] = $error;
        } else {
            [, $domainName] = explode('@', $email);
            $rules['name'] = ['required', 'string', new GroupName($wallet->owner, $domainName)];
        }

        // Validate the group name
        $v = Validator::make($request->all(), $rules);

        if ($v->fails()) {
            $errors = array_merge($errors, $v->errors()->toArray());
        }

        // Validate members' email addresses
        if (empty($members) || !is_array($members)) {
            $errors['members'] = self::trans('validation.listmembersrequired');
        } else {
            foreach ($members as $i => $member) {
                if (is_string($member) && !empty($member)) {
                    if ($error = self::validateMemberEmail($member, $wallet->owner)) {
                        $errors['members'][$i] = $error;
                    } elseif (\strtolower($member) === \strtolower($email)) {
                        $errors['members'][$i] = self::trans('validation.memberislist');
                    }
                } else {
                    unset($members[$i]);
                }
            }
        }

        if (!empty($errors)) {
            return response()->json(['status' => 'error', 'errors' => /* @var array */ $errors], 422);
        }

        DB::beginTransaction();

        if ($this->deleteBeforeCreate) {
            $this->deleteBeforeCreate->forceDelete();
        }

        // Create the group
        $group = new Group();
        $group->name = $request->input('name');
        $group->email = $email;
        $group->save();

        $group->setAddresses($members, true);
        $group->assignToWallet($wallet);

        DB::commit();

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.distlist-create-success'),
        ]);
    }

    /**
     * Update a group.
     *
     * @param Request $request the API request
     * @param string  $id      Group identifier
     */
    #[BodyParameter('name', description: 'Group name', type: 'string')]
    #[BodyParameter('members', description: 'Member email addresses', type: 'array<string>', required: true)]
    public function update(Request $request, $id): JsonResponse
    {
        $group = Group::find($id);

        if (!$this->checkTenant($group)) {
            return $this->errorResponse(404);
        }

        $current_user = $this->guard()->user();

        if (!$current_user->canUpdate($group)) {
            return $this->errorResponse(403);
        }

        $owner = $group->wallet()->owner;
        $name = $request->input('name');
        $members = $request->input('members');
        $errors = [];

        // Validate the group name
        if ($name !== null && $name != $group->name) {
            [, $domainName] = explode('@', $group->email);
            $rules = ['name' => ['required', 'string', new GroupName($owner, $domainName)]];

            $v = Validator::make($request->all(), $rules);

            if ($v->fails()) {
                $errors = array_merge($errors, $v->errors()->toArray());
            } else {
                $group->name = $name;
            }
        }

        // Validate members' email addresses
        if (empty($members) || !is_array($members)) {
            $errors['members'] = self::trans('validation.listmembersrequired');
        } else {
            foreach ((array) $members as $i => $member) {
                if (is_string($member) && !empty($member)) {
                    if ($error = self::validateMemberEmail($member, $owner)) {
                        $errors['members'][$i] = $error;
                    } elseif (\strtolower($member) === $group->email) {
                        $errors['members'][$i] = self::trans('validation.memberislist');
                    }
                } else {
                    unset($members[$i]);
                }
            }
        }

        if (!empty($errors)) {
            return response()->json(['status' => 'error', 'errors' => $errors], 422);
        }

        // SkusController::updateEntitlements($group, $request->skus);

        $group->save();
        $group->setAddresses($members);

        return response()->json([
            'status' => 'success',
            'message' => self::trans('app.distlist-update-success'),
        ]);
    }

    /**
     * Execute (synchronously) specified step in a group setup process.
     *
     * @param Group  $group Group object
     * @param string $step  Step identifier (as in self::statusInfo())
     *
     * @return bool|null True if the execution succeeded, False if not, Null when
     *                   the job has been sent to the worker (result unknown)
     */
    public static function execProcessStep(Group $group, string $step): ?bool
    {
        try {
            if (str_starts_with($step, 'domain-')) {
                return DomainsController::execProcessStep($group->domain(), $step);
            }

            switch ($step) {
                case 'distlist-ldap-ready':
                    // Group not in LDAP, create it
                    CreateJob::dispatch($group->id);
                    return null;
            }
        } catch (\Exception $e) {
            \Log::error($e);
        }

        return false;
    }

    /**
     * Validate an email address for use as a group email
     *
     * @param string $email   Email address
     * @param User   $user    The group owner
     * @param mixed  $deleted Filled with an instance of a deleted model object
     *                        with the specified email address, if exists
     *
     * @return ?string Error message on validation error
     */
    public static function validateGroupEmail($email, User $user, &$deleted = null): ?string
    {
        if (empty($email)) {
            return self::trans('validation.required', ['attribute' => 'email']);
        }

        if (!str_contains($email, '@')) {
            return self::trans('validation.entryinvalid', ['attribute' => 'email']);
        }

        [$login, $domain] = explode('@', \strtolower($email));

        if ($login === '' || $domain === '') {
            return self::trans('validation.entryinvalid', ['attribute' => 'email']);
        }

        // Check if domain exists
        $domain = Domain::where('namespace', $domain)->first();

        if (empty($domain)) {
            return self::trans('validation.domaininvalid');
        }

        $wallet = $domain->wallet();

        // The domain must be owned by the user
        if (!$wallet || !$user->wallets()->find($wallet->id)) {
            return self::trans('validation.domainnotavailable');
        }

        // Validate login part alone
        $v = Validator::make(
            ['email' => $login],
            ['email' => [new UserEmailLocal(true)]]
        );

        if ($v->fails()) {
            return $v->errors()->toArray()['email'][0];
        }

        // Check if the address is already taken
        if ($existing = self::findEmail($email)) {
            // If this is a deleted user/group/resource/folder in the same custom domain
            // we'll force delete it before creating the target group
            if (is_object($existing) && !$domain->isPublic() && $existing->trashed()) {
                $deleted = $existing;
            } else {
                return self::trans('validation.entryexists', ['attribute' => 'email']);
            }
        }

        return null;
    }

    /**
     * Validate an email address for use as a group member
     *
     * @param string $email Email address
     * @param User   $user  The group owner
     *
     * @return ?string Error message on validation error
     */
    public static function validateMemberEmail($email, User $user): ?string
    {
        $v = Validator::make(
            ['email' => $email],
            ['email' => [new ExternalEmail()]]
        );

        if ($v->fails()) {
            return $v->errors()->toArray()['email'][0];
        }

        // A local domain user must exist
        if (!User::where('email', \strtolower($email))->first()) {
            [$login, $domain] = explode('@', \strtolower($email));

            $domain = Domain::where('namespace', $domain)->first();

            // We return an error only if the domain belongs to the group owner
            if ($domain && ($wallet = $domain->wallet()) && $user->wallets()->find($wallet->id)) {
                return self::trans('validation.notalocaluser');
            }
        }

        return null;
    }
}
