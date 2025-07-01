<?php

namespace App\Jobs\Group;

use App\Group;
use App\Jobs\GroupJob;
use App\Support\Facades\LDAP;

class CreateJob extends GroupJob
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

        if ($group->trashed()) {
            return;
        }

        if (\config('app.with_ldap') && !$group->isLdapReady()) {
            LDAP::createGroup($group);

            $group->status |= Group::STATUS_LDAP_READY;
        }

        $group->status |= Group::STATUS_ACTIVE;
        $group->save();
    }
}
