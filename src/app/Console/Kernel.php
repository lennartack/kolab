<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule The application's command schedule
     */
    protected function schedule(Schedule $schedule): void
    {
        // This imports countries and the current set of IPv4 and IPv6 networks allocated to countries.
        $schedule->command('data:import')->dailyAt('05:00');

        // This notifies users about coming password expiration
        $schedule->command('password:retention')->dailyAt('06:00');

        if (\config('app.with_wallet')) {
            // This applies wallet charges
            $schedule->command('wallet:charge')->everyFourHours();

            // This notifies users about an end of the trial period
            $schedule->command('wallet:trial-end')->dailyAt('07:00');
        }

        // This removes deleted storage files/file chunks from the filesystem
        $schedule->command('fs:expunge')->hourly();

        // This cleans up IMAP ACL for deleted users/etc.
        // $schedule->command('imap:cleanup')->dailyAt('03:00');

        // This collects some statistics into the database
        $schedule->command('data:stats:collector')->dailyAt('23:00');

        // https://laravel.com/docs/10.x/upgrade#redis-cache-tags
        $schedule->command('cache:prune-stale-tags')->hourly();

        // This removes passport expired/revoked tokens and auth codes from the database
        $schedule->command('passport:purge')->dailyAt('06:30');

        // Keep the database size under control (every Monday)
        $schedule->command('db:expunge')->weeklyOn(1, '04:00');

        // Make sure all deleted users are processed (delete jobs finish)
        $schedule->command('user:resync --deleted-only')->dailyAt('04:30');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        if (\app('env') == 'local') {
            $this->load(__DIR__ . '/Development');
        }

        include base_path('routes/console.php');
    }
}
