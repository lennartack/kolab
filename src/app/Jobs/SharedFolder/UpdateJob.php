<?php

namespace App\Jobs\SharedFolder;

use App\Jobs\SharedFolderJob;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;

class UpdateJob extends SharedFolderJob
{
    /**
     * Execute the job.
     */
    public function handle()
    {
        $folder = $this->getSharedFolder();

        if (!$folder) {
            return;
        }

        if ($folder->trashed()) {
            return;
        }

        if (\config('app.with_ldap') && $folder->isLdapReady()) {
            LDAP::updateSharedFolder($folder);
        }

        if (\config('app.with_imap') && $folder->isImapReady()) {
            if (!IMAP::updateSharedFolder($folder, $this->properties)) {
                throw new \Exception("Failed to update mailbox for shared folder {$this->folderId}.");
            }
        }
    }
}
