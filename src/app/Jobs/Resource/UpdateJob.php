<?php

namespace App\Jobs\Resource;

use App\Jobs\ResourceJob;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;

class UpdateJob extends ResourceJob
{
    /**
     * Execute the job.
     */
    public function handle()
    {
        $resource = $this->getResource();

        if (!$resource) {
            return;
        }

        if ($resource->trashed()) {
            return;
        }

        if (\config('app.with_ldap') && $resource->isLdapReady()) {
            LDAP::updateResource($resource);
        }

        if (\config('app.with_imap') && $resource->isImapReady()) {
            if (!IMAP::updateResource($resource, $this->properties)) {
                throw new \Exception("Failed to update mailbox for resource {$this->resourceId}.");
            }
        }
    }
}
