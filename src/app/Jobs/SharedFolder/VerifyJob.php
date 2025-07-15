<?php

namespace App\Jobs\SharedFolder;

use App\Jobs\SharedFolderJob;
use App\SharedFolder;
use App\Support\Facades\IMAP;

class VerifyJob extends SharedFolderJob
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

        if ($folder->trashed() || $folder->isImapReady()) {
            return;
        }

        $folderName = $folder->getSetting('folder');

        if (IMAP::verifySharedFolder($folderName)) {
            $folder->status |= SharedFolder::STATUS_IMAP_READY;
            $folder->status |= SharedFolder::STATUS_ACTIVE;
            $folder->saveQuietly();
        }
    }
}
