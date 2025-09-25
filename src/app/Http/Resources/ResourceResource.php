<?php

namespace App\Http\Resources;

use App\Http\Controllers\RelationController;
use App\Resource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource response
 *
 * @mixin Resource
 */
class ResourceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $state = RelationController::objectState($this->resource);

        return [
            // @var int Resource identifier
            'id' => $this->resource->id,
            // Resource email address
            'email' => $this->resource->email,
            // Resource name
            'name' => $this->resource->name,

            // @var bool Is resource active?
            'isActive' => $state['isActive'] ?? false,
            // @var bool Is resource deleted?
            'isDeleted' => $state['isDeleted'] ?? false,
            // @var bool Readiness state
            'isReady' => $state['isReady'],
            // @var bool IMAP readiness state
            'isImapReady' => $state['isImapReady'] ?? false,
            // @var bool LDAP readiness state
            'isLdapReady' => $this->when(isset($state['isLdapReady']), $state['isLdapReady'] ?? false),
            // @var bool Is resource suspended?
            // 'isSuspended' => $state['isSuspended'] ?? false,
        ];
    }
}
