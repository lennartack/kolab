<?php

namespace App\Jobs\User;

use App\Jobs\UserJob;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;
use App\Support\Facades\Roundcube;
use App\User;

class DeleteJob extends UserJob
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

        if (!$user->trashed()) {
            $this->fail("User {$user->email} is not deleted.");
            return;
        }

        if ($user->isDeleted()) {
            return;
        }

        if (\config('app.with_ldap') && $user->isLdapReady()) {
            LDAP::deleteUser($user);

            $user->status ^= User::STATUS_LDAP_READY;
            $user->saveQuietly();
        }

        if ($user->isImapReady()) {
            if (\config('app.with_imap')) {
                if (!IMAP::deleteUser($user)) {
                    throw new \Exception("Failed to delete mailbox for user {$user->email}.");
                }
            }

            $user->status ^= User::STATUS_IMAP_READY;
        }

        if (\config('database.connections.roundcube')) {
            Roundcube::deleteUser($user->email);
        }

        $user->status |= User::STATUS_DELETED;
        $user->saveQuietly();
    }
}
