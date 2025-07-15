<?php

namespace App\Jobs\Resource;

use App\Jobs\ResourceJob;
use App\Resource;
use App\Support\Facades\IMAP;

class VerifyJob extends ResourceJob
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

        if ($resource->trashed() || $resource->isImapReady()) {
            return;
        }

        $folder = $resource->getSetting('folder');

        if ($folder && IMAP::verifySharedFolder($folder)) {
            $resource->status |= Resource::STATUS_IMAP_READY;
            $resource->status |= Resource::STATUS_ACTIVE;
            $resource->saveQuietly();
        }
    }
}
