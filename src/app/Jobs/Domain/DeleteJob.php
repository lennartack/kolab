<?php

namespace App\Jobs\Domain;

use App\Domain;
use App\Jobs\DomainJob;
use App\Support\Facades\LDAP;

class DeleteJob extends DomainJob
{
    /**
     * Execute the job.
     */
    public function handle()
    {
        $domain = $this->getDomain();

        if (!$domain) {
            return;
        }

        if (!$domain->trashed()) {
            $this->fail("Domain {$domain->namespace} is not deleted.");
            return;
        }

        if ($domain->isDeleted()) {
            return;
        }

        if (\config('app.with_ldap') && $domain->isLdapReady()) {
            LDAP::deleteDomain($domain);

            $domain->status ^= Domain::STATUS_LDAP_READY;
        }

        $domain->status |= Domain::STATUS_DELETED;
        $domain->save();
    }
}
