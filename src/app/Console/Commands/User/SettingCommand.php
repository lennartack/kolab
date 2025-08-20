<?php

namespace App\Console\Commands\User;

use App\Console\Command;

class SettingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:setting {user} {key?}';

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

        if (empty($this->argument('key'))) {
            $user->settings()->orderBy('key')->each(
                function ($setting) {
                    if ($setting->value !== null) {
                        $this->info("{$setting->key}: " . \str_replace("\n", ' ', $setting->value));
                    }
                }
            );
        } else {
            $this->info($user->getSetting($this->argument('key')));
        }
    }
}
