<?php

namespace App\Jobs\Resource;

use App\Jobs\ResourceJob;
use App\Resource;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;

class DeleteJob extends ResourceJob
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

        if (!$resource->trashed()) {
            $this->fail("Resource {$this->resourceId} is not deleted.");
            return;
        }

        if ($resource->isDeleted()) {
            return;
        }

        if (\config('app.with_ldap') && $resource->isLdapReady()) {
            LDAP::deleteResource($resource);

            $resource->status ^= Resource::STATUS_LDAP_READY;
            $resource->saveQuietly();
        }

        if ($resource->isImapReady()) {
            if (\config('app.with_imap')) {
                if (!IMAP::deleteResource($resource)) {
                    throw new \Exception("Failed to delete mailbox for resource {$this->resourceId}.");
                }
            }

            $resource->status ^= Resource::STATUS_IMAP_READY;
        }

        $resource->status |= Resource::STATUS_DELETED;
        $resource->saveQuietly();
    }
}
