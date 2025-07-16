<?php

namespace App\Console\Commands\User;

use App\Console\Command;
use App\User;

class SetSettingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:set-setting {user} {key} {--delete} {value?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Set a user setting";

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

        if ($this->option("delete")) {
            $user->removeSetting($this->argument('key'));
        } else {
            $user->setSetting($this->argument('key'), $this->argument('value'));
        }
    }
}
