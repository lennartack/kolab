<?php

namespace App\Jobs\Group;

use App\Jobs\GroupJob;
use App\Support\Facades\LDAP;

class UpdateJob extends GroupJob
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

        // Cancel the update if the group is deleted or not yet in LDAP
        if (!\config('app.with_ldap') || !$group->isLdapReady() || $group->trashed()) {
            return;
        }

        LDAP::connect();

        // Groups does not have an attribute for the status, therefore
        // we remove suspended groups from LDAP.
        // We do not remove STATUS_LDAP_READY flag because it is part of the
        // setup process.

        $inLdap = !empty(LDAP::getGroup($group->email));

        if ($group->isSuspended() && $inLdap) {
            LDAP::deleteGroup($group);
        } elseif (!$group->isSuspended() && !$inLdap) {
            LDAP::createGroup($group);
        } else {
            LDAP::updateGroup($group);
        }

        LDAP::disconnect();
    }
}
