<?php

namespace App\Jobs\SharedFolder;

use App\Jobs\SharedFolderJob;
use App\SharedFolder;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;

class CreateJob extends SharedFolderJob
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

        $withLdap = \config('app.with_ldap');

        // see if the domain is ready
        $domain = $folder->domain();

        if (!$domain) {
            $this->fail("The domain for shared folder {$this->folderId} does not exist.");
            return;
        }

        if ($domain->isDeleted()) {
            $this->fail("The domain for shared folder {$this->folderId} is marked as deleted.");
            return;
        }

        if ($withLdap && !$domain->isLdapReady()) {
            $this->release(60);
            return;
        }

        if ($withLdap && !$folder->isLdapReady()) {
            LDAP::createSharedFolder($folder);

            $folder->status |= SharedFolder::STATUS_LDAP_READY;
            $folder->save();
        }

        if (!$folder->isImapReady()) {
            if (\config('app.with_imap')) {
                if (!IMAP::createSharedFolder($folder)) {
                    throw new \Exception("Failed to create mailbox for shared folder {$this->folderId}.");
                }
            } else {
                $folderName = $folder->getSetting('folder');

                if ($folderName && !IMAP::verifySharedFolder($folderName)) {
                    $this->release(15);
                    return;
                }
            }

            $folder->status |= SharedFolder::STATUS_IMAP_READY;
        }

        $folder->status |= SharedFolder::STATUS_ACTIVE;
        $folder->save();
    }
}
