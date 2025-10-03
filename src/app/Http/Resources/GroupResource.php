<?php

namespace App\Http\Resources;

use App\Group;
use App\Http\Controllers\RelationController;
use Illuminate\Http\Request;

/**
 * Group response
 *
 * @mixin Group
 */
class GroupResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $state = RelationController::objectState($this->resource);

        return [
            // @var int Group identifier
            'id' => $this->resource->id,
            // Group email address
            'email' => $this->resource->email,
            // Group name
            'name' => $this->resource->name,

            $this->mergeWhen(self::isAdmin(), [
                /*
                 * @var string Group creation date-time
                 * @format date-time
                 */
                'created_at' => (string) $this->resource->created_at,
                /*
                 * @var string Group deletion date-time
                 * @format date-time
                 */
                'deleted_at' => (string) $this->resource->deleted_at,
            ]),

            // @var bool Is group active?
            'isActive' => $state['isActive'] ?? false,
            // @var bool Is group deleted?
            'isDeleted' => $state['isDeleted'] ?? false,
            // @var bool Readiness state
            'isReady' => $state['isReady'],
            // @var bool IMAP readiness state
            'isImapReady' => $state['isImapReady'] ?? false,
            // @var bool LDAP readiness state
            'isLdapReady' => $this->when(isset($state['isLdapReady']), $state['isLdapReady'] ?? false),
            // @var bool Is group suspended?
            'isSuspended' => $state['isSuspended'] ?? false,
        ];
    }
}
