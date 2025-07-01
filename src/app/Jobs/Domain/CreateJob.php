<?php

namespace App\Jobs\Domain;

use App\Domain;
use App\Jobs\DomainJob;
use App\Support\Facades\LDAP;

class CreateJob extends DomainJob
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

        if (\config('app.with_ldap') && !$domain->isLdapReady()) {
            LDAP::createDomain($domain);

            $domain->status |= Domain::STATUS_LDAP_READY;
            $domain->save();
        }

        VerifyJob::dispatch($domain->id);
    }
}
