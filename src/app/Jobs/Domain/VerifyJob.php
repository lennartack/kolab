<?php

namespace App\Jobs\Domain;

use App\Jobs\DomainJob;

class VerifyJob extends DomainJob
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

        $domain->verify();
    }
}
