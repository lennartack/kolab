<?php

namespace App\Observers;

use App\Jobs\User\UpdateJob;
use App\Support\Facades\Roundcube;
use App\UserSetting;

class UserSettingObserver
{
    /**
     * Handle the user setting "created" event.
     *
     * @param UserSetting $userSetting Settings object
     */
    public function created(UserSetting $userSetting)
    {
        $this->dispatchUpdateJob($userSetting);
    }

    /**
     * Handle the user setting "updated" event.
     *
     * @param UserSetting $userSetting Settings object
     */
    public function updated(UserSetting $userSetting)
    {
        $this->dispatchUpdateJob($userSetting);
    }

    /**
     * Handle the user setting "deleted" event.
     *
     * @param UserSetting $userSetting Settings object
     */
    public function deleted(UserSetting $userSetting)
    {
        $this->dispatchUpdateJob($userSetting);
    }

    /**
     * Dispatch the user update job (if needed).
     *
     * @param UserSetting $userSetting Settings object
     */
    private function dispatchUpdateJob(UserSetting $userSetting): void
    {
        if ($userSetting->isBackendSetting()) {
            UpdateJob::dispatch($userSetting->user_id);
        }

        if ($userSetting->key === 'debug') {
            Roundcube::resetConfigCache($userSetting->user);
        }
    }
}
