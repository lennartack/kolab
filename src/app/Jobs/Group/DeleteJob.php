<?php

namespace App\Jobs\Group;

use App\Group;
use App\Jobs\GroupJob;
use App\Support\Facades\LDAP;

class DeleteJob extends GroupJob
{
    /**
     * Execute the job.
     */
    public function handle()
    {
        $group = $this->getGroup();

        if (!$group) {
            return;
        }

        if (!$group->trashed()) {
            $this->fail("Group {$group->email} is not deleted.");
            return;
        }

        if ($group->isDeleted()) {
            return;
        }

        if (\config('app.with_ldap') && $group->isLdapReady()) {
            LDAP::deleteGroup($group);

            $group->status ^= Group::STATUS_LDAP_READY;
        }

        $group->status |= Group::STATUS_DELETED;
        $group->saveQuietly();
    }
}
