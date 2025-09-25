<?php

namespace App\Http\Resources;

use App\Domain;
use App\Http\Controllers\RelationController;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Domain response
 *
 * @mixin Domain
 */
class DomainResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $state = RelationController::objectState($this->resource);

        return [
            // Domain identifier
            'id' => $this->resource->id,
            // Domain namespace
            'namespace' => $this->resource->namespace,
            // Domain type
            'type' => $this->resource->type,

            // @var bool Is domain active?
            'isActive' => $state['isActive'] ?? false,
            // @var bool Is domain deleted?
            'isDeleted' => $state['isDeleted'] ?? false,
            // @var bool Readiness state
            'isReady' => $state['isReady'],
            // @var bool IMAP readiness state
            'isImapReady' => $state['isImapReady'] ?? false,
            // @var bool LDAP readiness state
            'isLdapReady' => $this->when(isset($state['isLdapReady']), $state['isLdapReady'] ?? false),
            // @var bool Is domain suspended?
            'isSuspended' => $state['isSuspended'] ?? false,
            // @var bool Is domain confirmed?
            'isConfirmed' => $state['isConfirmed'] ?? false,
            // @var bool Is domain verified?
            'isVerified' => $state['isVerified'] ?? false,
        ];
    }
}
