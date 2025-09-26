<?php

namespace App\Http\Resources;

use App\Http\Controllers\RelationController;
use App\SharedFolder;
use Illuminate\Http\Request;

/**
 * Shared folder response
 *
 * @mixin SharedFolder
 */
class SharedFolderResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $state = RelationController::objectState($this->resource);

        return [
            // @var int Folder identifier
            'id' => $this->resource->id,
            // Folder email address
            'email' => $this->resource->email,
            // Folder name
            'name' => $this->resource->name,
            // Folder type
            'type' => $this->resource->type,

            // @var bool Is folder active?
            'isActive' => $state['isActive'] ?? false,
            // @var bool Is folder deleted?
            'isDeleted' => $state['isDeleted'] ?? false,
            // @var bool Readiness state
            'isReady' => $state['isReady'],
            // @var bool IMAP readiness state
            'isImapReady' => $state['isImapReady'] ?? false,
            // @var bool LDAP readiness state
            'isLdapReady' => $this->when(isset($state['isLdapReady']), $state['isLdapReady'] ?? false),
            // @var bool Is folder suspended?
            // 'isSuspended' => $state['isSuspended'] ?? false,
        ];
    }
}
