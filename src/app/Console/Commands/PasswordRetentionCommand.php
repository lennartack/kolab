<?php

namespace App\Console\Commands;

use App\Console\Command;
use App\Jobs\Mail\PasswordRetentionJob;
use App\User;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PasswordRetentionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'password:retention';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Handles password expiration and sends related email notification.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Get all users (accounts) with max_password_age set
        $accounts = User::select('users.*', 'user_settings.value as max_age')
            ->join('user_settings', 'users.id', '=', 'user_settings.user_id')
            ->where('user_settings.key', 'max_password_age')
            ->cursor();

        foreach ($accounts as $account) {
            // For all users in the account (get the password update date)...
            $account->users(false)
                ->addSelect(
                    DB::raw("(select value from user_settings"
                        . " where users.id = user_settings.user_id and user_settings.key = 'password_update'"
                        . ") as password_update")
                )
                // skip users with expired password
                ->whereNotExists(function (Builder $query) {
                    $query->select(DB::raw(1))
                        ->from('user_settings')
                        ->where('key', 'password_expired')
                        ->whereColumn('user_settings.user_id', 'users.id');
                })
                // Skip incomplete or suspended users
                ->where('status', '&', User::STATUS_IMAP_READY)
                ->whereNot('status', '&', User::STATUS_SUSPENDED)
                ->get()
                ->each(static function ($user) use ($account) {
                    /** @var User $user */
                    // If the password was never updated use the user creation time
                    if (!empty($user->password_update)) {
                        $lastUpdate = new Carbon($user->password_update);
                    } else {
                        $lastUpdate = $user->created_at;
                    }

                    // @phpstan-ignore-next-line
                    $nextUpdate = $lastUpdate->copy()->addMonthsWithoutOverflow((int) $account->max_age);
                    $diff = Carbon::now()->diffInDays($nextUpdate, false);

                    // The password already expired
                    if ($diff <= 0) {
                        // TODO: Invalidate all existing "session" tokens if possible?
                        $user->setSetting('password_expired', Carbon::now()->toDateTimeString());
                        return;
                    }

                    if ($warnedOn = $user->getSetting('password_expiration_warning')) {
                        $warnedOn = new Carbon($warnedOn);
                    }

                    // The password expires in 14 days or less
                    if ($diff <= 14) {
                        // Send a warning if it wasn't sent yet or 7 days passed since the last warning.
                        // Which means that we send the email 14 and 7 days before the password expires.
                        if (empty($warnedOn) || $warnedOn->diffInDays(Carbon::now(), false) > 7) {
                            PasswordRetentionJob::dispatch($user, $nextUpdate->toDateString());
                        }
                    }
                });
        }
    }
}
