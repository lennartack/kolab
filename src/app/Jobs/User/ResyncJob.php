<?php

namespace App\Jobs\User;

use App\Domain;
use App\Jobs\Domain\CreateJob;
use App\Jobs\UserJob;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;
use App\User;

class ResyncJob extends UserJob
{
    /**
     * Execute the job.
     */
    public function handle()
    {
        $this->logJobStart($this->userEmail);

        $user = $this->getUser();

        if (!$user) {
            return;
        }

        if ($user->role == User::ROLE_SERVICE) {
            return;
        }

        $withLdap = \config('app.with_ldap');

        $userJob = UpdateJob::class;

        // Make sure the LDAP entry exists, fix that
        if ($withLdap && $user->isLdapReady()) {
            // Check (and fix) the custom domain state
            $domain = $user->domain();
            if (!$domain->isPublic() && !LDAP::getDomain($domain->namespace)) {
                $domain->status &= ~Domain::STATUS_LDAP_READY;
                $domain->saveQuietly();

                CreateJob::dispatchSync($domain->id);
            }

            if (!LDAP::getUser($user->email)) {
                $user->status &= ~User::STATUS_LDAP_READY;
                $userJob = \App\Jobs\User\CreateJob::class;
            }
        }

        // Make sure the IMAP mailbox exists too
        if ($user->isImapReady()) {
            if (!IMAP::verifyAccount($user->email)) {
                $user->status &= ~User::STATUS_IMAP_READY;
                $userJob = \App\Jobs\User\CreateJob::class;
            }
        }

        $user->saveQuietly();

        $userJob::dispatchSync($user->id);
    }
}
