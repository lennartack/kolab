<?php

namespace App\Jobs\User;

use App\Jobs\UserJob;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;
use App\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

class UpdateJob extends UserJob implements ShouldBeUniqueUntilProcessing
{
    /** @var int Enable waiting for a user record to exist */
    protected $waitForUser = 5;

    /** @var int The number of seconds after which the job's unique lock will be released. */
    public $uniqueFor = 60;

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

        if ($user->role == User::ROLE_SERVICE || $user->role == User::ROLE_DEVICE) {
            // Admins/resellers don't reside in LDAP (for now)
            return;
        }

        if ($user->trashed()) {
            return;
        }

        if (\config('app.with_ldap') && $user->isLdapReady()) {
            LDAP::updateUser($user);
        }

        if (\config('app.with_imap') && $user->isImapReady()) {
            if (!IMAP::updateUser($user)) {
                throw new \Exception("Failed to update mailbox for user {$user->email}.");
            }
        }
    }

    /**
     * Get the unique ID for the job.
     *
     * This together with ShouldBeUniqueUntilProcessing makes sure there's only one update job
     * for the same user at the same time. E.g. if you delete 5 storage entitlements in one action,
     * we'll reach to LDAP/IMAP backend only once (instead of five).
     */
    public function uniqueId(): string
    {
        return (string) $this->userId;
    }
}
