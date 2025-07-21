<?php

namespace App\Console\Commands\User;

use App\Console\Command;

class DebugStopCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:debug-stop {user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable debug for user.';

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

        $user->removeSetting('debug');
    }
}
