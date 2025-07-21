<?php

namespace App\Console\Commands\User;

use App\Console\Command;

class DebugStartCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:debug-start {user} {mode?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enable debug for user.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $user = $this->getUser($this->argument('user'));

        if (!$user) {
            $this->error("User not found.");
            return 1;
        }

        $mode = \strtolower($this->argument('mode') ?: 'roundcube,syncroton,chwala');

        $user->setSetting('debug', $mode);
    }
}
