<?php

namespace App\Jobs\SharedFolder;

use App\Jobs\SharedFolderJob;
use App\SharedFolder;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;

class DeleteJob extends SharedFolderJob
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

        if (!$folder->trashed()) {
            $this->fail("Shared folder {$folder->id} is not deleted.");
            return;
        }

        if ($folder->isDeleted()) {
            return;
        }

        if (\config('app.with_ldap') && $folder->isLdapReady()) {
            LDAP::deleteSharedFolder($folder);

            $folder->status ^= SharedFolder::STATUS_LDAP_READY;
            // Already save in case of exception below
            $folder->save();
        }

        if ($folder->isImapReady()) {
            if (\config('app.with_imap')) {
                if (!IMAP::deleteSharedFolder($folder)) {
                    throw new \Exception("Failed to delete mailbox for shared folder {$this->folderId}.");
                }
            }

            $folder->status ^= SharedFolder::STATUS_IMAP_READY;
        }

        $folder->status |= SharedFolder::STATUS_DELETED;
        $folder->save();
    }
}
