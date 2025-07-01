<?php

namespace App\Jobs\Resource;

use App\Jobs\ResourceJob;
use App\Resource;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;

class CreateJob extends ResourceJob
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

        $withLdap = \config('app.with_ldap');

        // see if the domain is ready
        $domain = $resource->domain();

        if (!$domain) {
            $this->fail("The domain for resource {$this->resourceId} does not exist.");
            return;
        }

        if ($domain->isDeleted()) {
            $this->fail("The domain for resource {$this->resourceId} is marked as deleted.");
            return;
        }

        if ($withLdap && !$domain->isLdapReady()) {
            $this->release(60);
            return;
        }

        if ($withLdap && !$resource->isLdapReady()) {
            LDAP::createResource($resource);

            $resource->status |= Resource::STATUS_LDAP_READY;
            $resource->save();
        }

        if (!$resource->isImapReady()) {
            if (\config('app.with_imap')) {
                if (!IMAP::createResource($resource)) {
                    throw new \Exception("Failed to create mailbox for resource {$this->resourceId}.");
                }
            } else {
                $folder = $resource->getSetting('folder');

                if ($folder && !IMAP::verifySharedFolder($folder)) {
                    $this->release(15);
                    return;
                }
            }

            $resource->status |= Resource::STATUS_IMAP_READY;
        }

        $resource->status |= Resource::STATUS_ACTIVE;
        $resource->save();
    }
}
