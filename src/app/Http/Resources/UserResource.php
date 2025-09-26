<?php

namespace App\Http\Resources;

use App\Http\Controllers\RelationController;
use App\User;
use Illuminate\Http\Request;

/**
 * User response
 *
 * @mixin User
 */
class UserResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $state = RelationController::objectState($this->resource);

        return [
            // User identifier
            'id' => $this->resource->id,
            // User email address
            'email' => $this->resource->email,
            // User status
            'status' => $this->resource->status,

            $this->mergeWhen(self::isAdmin(), [
                // User creation date-time
                'created_at' => (string) $this->resource->created_at,
                // User deletion date-time
                'deleted_at' => (string) $this->resource->deleted_at,
            ]),

            // @var bool Is user active?
            'isActive' => $state['isActive'] ?? false,
            // @var bool Is user deleted?
            'isDeleted' => $state['isDeleted'] ?? false,
            // @var bool Is user degraded?
            'isDegraded' => $state['isDegraded'] ?? false,
            // @var bool Readiness state
            'isReady' => $state['isReady'],
            // @var bool IMAP readiness state
            'isImapReady' => $state['isImapReady'] ?? false,
            // @var bool LDAP readiness state
            'isLdapReady' => $this->when(isset($state['isLdapReady']), $state['isLdapReady'] ?? false),
            // @var bool Is user restricted?
            'isRestricted' => $state['isRestricted'] ?? false,
            // @var bool Is user suspended?
            'isSuspended' => $state['isSuspended'] ?? false,

            // @var bool Is account owner degraded?
            'isAccountDegraded' => $this->resource->isDegraded(true),
            // TODO: Above property is a performance issue in users list context
            // It probably should be moved to UserInfoResource, but carefully
        ];
    }
}
