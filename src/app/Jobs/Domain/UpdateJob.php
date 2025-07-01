<?php

namespace App\Jobs\Domain;

use App\Jobs\DomainJob;
use App\Support\Facades\LDAP;

class UpdateJob extends DomainJob
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

        if ($domain->trashed()) {
            return;
        }

        if (!\config('app.with_ldap') || !$domain->isLdapReady()) {
            return;
        }

        LDAP::updateDomain($domain);
    }
}
