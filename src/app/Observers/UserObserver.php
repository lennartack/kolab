<?php

namespace App\Observers;

use App\Delegation;
use App\Entitlement;
use App\EventLog;
use App\Group;
use App\Jobs\PGP\KeyCreateJob;
use App\Jobs\PGP\KeyDeleteJob;
use App\Jobs\User\CreateJob;
use App\Jobs\User\DeleteJob;
use App\Jobs\User\UpdateJob;
use App\Policy\Password;
use App\Policy\RateLimit\Whitelist;
use App\Tenant;
use App\Transaction;
use App\User;
use App\Utils;
use App\Wallet;
use Carbon\Carbon;

class UserObserver
{
    /**
     * Handle the "creating" event.
     *
     * Ensure that the user is created with a random, large integer.
     *
     * @param User $user the user being created
     */
    public function creating(User $user)
    {
        $user->email = \strtolower($user->email);

        $user->status |= User::STATUS_NEW;
    }

    /**
     * Handle the "created" event.
     *
     * Ensures the user has at least one wallet.
     *
     * Should ensure some basic settings are available as well.
     *
     * @param User $user the user created
     */
    public function created(User $user)
    {
        if ($user->role == User::ROLE_SERVICE) {
            return;
        }

        $settings = [
            'country' => Utils::countryForRequest(),
            'currency' => \config('app.currency'),
            /*
            'first_name' => '',
            'last_name' => '',
            'billing_address' => '',
            'organization' => '',
            'phone' => '',
            'external_email' => '',
            */
        ];

        foreach ($settings as $key => $value) {
            $settings[$key] = [
                'key' => $key,
                'value' => $value,
                'user_id' => $user->id,
            ];
        }

        // Note: Don't use setSettings() here to bypass UserSetting observers
        // Note: This is a single multi-insert query
        $user->settings()->insert(array_values($settings));

        $user->wallets()->create();

        // Create user record in the backend (LDAP and IMAP)
        CreateJob::dispatch($user->id);

        if (Tenant::getConfig($user->tenant_id, 'pgp.enable')) {
            KeyCreateJob::dispatch($user->id, $user->email);
        }
    }

    /**
     * Handle the "deleted" event.
     *
     * @param User $user the user deleted
     */
    public function deleted(User $user)
    {
        if ($user->role == User::ROLE_SERVICE) {
            return;
        }

        if (!$user->isForceDeleting()) {
            DeleteJob::dispatch($user->id);

            if (Tenant::getConfig($user->tenant_id, 'pgp.enable')) {
                KeyDeleteJob::dispatch($user->id, $user->email);
            }
        }

        // Remove the user from existing groups
        $wallet = $user->wallet();
        if ($wallet && $wallet->owner) {
            $wallet->owner->groups()->each(static function ($group) use ($user) {
                /** @var Group $group */
                if (in_array($user->email, $group->members)) {
                    $group->members = array_diff($group->members, [$user->email]);
                    $group->save();
                }
            });
        }

        // Remove delegation relations
        $ids = Delegation::where('user_id', $user->id)->orWhere('delegatee_id', $user->id)->get()
            ->map(static function ($delegation) use ($user) {
                $delegator = $delegation->user_id == $user->id
                    ? $user : $delegation->user()->withTrashed()->first();
                $delegatee = $delegation->delegatee_id == $user->id
                    ? $user : $delegation->delegatee()->withTrashed()->first();

                \App\Jobs\User\Delegation\DeleteJob::dispatch($delegator->email, $delegatee->email);

                return $delegation->id;
            })
            ->all();

        if (!empty($ids)) {
            Delegation::whereIn('id', $ids)->delete();
        }

        // TODO: Remove Permission records for the user
        // TODO: Remove file permissions for the user
    }

    /**
     * Handle the "deleting" event.
     *
     * @param User $user the user that is being deleted
     */
    public function deleting(User $user)
    {
        if ($user->role == User::ROLE_SERVICE) {
            return;
        }

        // Remove owned users/domains/groups/resources/etc
        self::removeRelatedObjects($user, $user->isForceDeleting());
    }

    /**
     * Handle the user "restoring" event.
     *
     * @param User $user The user
     */
    public function restoring(User $user)
    {
        // Reset the status
        $user->status = User::STATUS_NEW;

        // Note: $user->save() is invoked between 'restoring' and 'restored' events
    }

    /**
     * Handle the user "restored" event.
     *
     * @param User $user The user
     */
    public function restored(User $user)
    {
        if ($user->role == User::ROLE_SERVICE) {
            return;
        }

        // We need at least the user domain so it can be created in ldap.
        // FIXME: What if the domain is owned by someone else?
        $domain = $user->domain();
        if ($domain->trashed() && !$domain->isPublic()) {
            // Note: Domain entitlements will be restored by the DomainObserver
            $domain->restore();
        }

        // FIXME: Should we reset user aliases? or re-validate them in any way?

        // Create user record in the backend (LDAP and IMAP)
        CreateJob::dispatch($user->id);
    }

    /**
     * Handle the "updated" event.
     *
     * @param User $user the user that is being updated
     */
    public function updated(User $user)
    {
        if ($user->role == User::ROLE_SERVICE) {
            return;
        }

        if (!$user->trashed()) {
            UpdateJob::dispatch($user->id);
        }

        $oldStatus = $user->getOriginal('status');
        $newStatus = $user->status;

        if (($oldStatus & User::STATUS_DEGRADED) !== ($newStatus & User::STATUS_DEGRADED)) {
            $wallets = [];
            $isDegraded = $user->isDegraded();

            // Charge all entitlements as if they were being deleted,
            // but don't delete them. Just debit the wallet and update
            // entitlements' updated_at timestamp. On un-degrade we still
            // update updated_at, but with no debit (the cost is 0 on a degraded account).
            foreach ($user->wallets as $wallet) {
                $wallet->updateEntitlements($isDegraded);

                // Remember time of the degradation for sending periodic reminders
                // and reset it on un-degradation
                $val = $isDegraded ? Carbon::now()->toDateTimeString() : null;
                $wallet->setSetting('degraded_last_reminder', $val);

                $wallets[] = $wallet->id;
            }

            // (Un-)degrade users by invoking an update job.
            // LDAP backend will read the wallet owner's degraded status and
            // set LDAP attributes accordingly.
            // We do not change their status as their wallets have its own state
            Entitlement::whereIn('wallet_id', $wallets)
                ->where('entitleable_id', '!=', $user->id)
                ->where('entitleable_type', User::class)
                ->pluck('entitleable_id')
                ->unique()
                ->each(static function ($user_id) {
                    UpdateJob::dispatch($user_id);
                });
        }

        // Password change
        $oldPassword = $user->getOriginal('password');
        if ($oldPassword && $user->password != $oldPassword) {
            // Reset the password expiration settings
            $user->removeSettingsQuietly(['password_expired', 'password_expiration_warning']);

            // Save the old password in the password history
            self::saveOldPassword($user, $oldPassword);
        }
    }

    /**
     * Remove entities related to the user (in user's wallets), entitlements, transactions, etc.
     *
     * @param User $user  The user
     * @param bool $force Force-delete mode
     */
    private static function removeRelatedObjects(User $user, $force = false): void
    {
        $wallets = $user->wallets->pluck('id')->all();

        Entitlement::withTrashed()
            ->select('entitleable_id', 'entitleable_type')
            ->distinct()
            ->whereIn('wallet_id', $wallets)
            ->get()
            ->each(static function ($entitlement) use ($user, $force) {
                // Skip the current user (infinite recursion loop)
                if ($entitlement->entitleable_type == User::class && $entitlement->entitleable_id == $user->id) {
                    return;
                }

                if (!$entitlement->entitleable) {
                    return;
                }

                // Objects need to be deleted one by one to make sure observers can do the proper cleanup
                if ($force) {
                    $entitlement->entitleable->forceDelete();
                } elseif (!$entitlement->entitleable->trashed()) {
                    $entitlement->entitleable->delete();
                }
            });

        if ($force) {
            // Remove "wallet" transactions, they have no foreign key constraint
            Transaction::where('object_type', Wallet::class)
                ->whereIn('object_id', $wallets)
                ->delete();

            // Remove EventLog records
            EventLog::where('object_id', $user->id)->where('object_type', User::class)->delete();
        }

        // regardless of force delete, we're always purging whitelists... just in case
        Whitelist::where(
            [
                'whitelistable_id' => $user->id,
                'whitelistable_type' => User::class,
            ]
        )->delete();
    }

    /**
     * Store the old password in user password history. Make sure
     * we do not store more passwords than we need in the history.
     *
     * @param User   $user     The user
     * @param string $password The old password
     */
    private static function saveOldPassword(User $user, string $password): void
    {
        // Remember the timestamp of the last password change
        // Note: We could get this from user_passwords table, but only if the policy
        // enables storing of old passwords there.
        $user->setSetting('password_update', now()->format('Y-m-d H:i:s'));

        Password::saveHash($user, $password);
    }
}
