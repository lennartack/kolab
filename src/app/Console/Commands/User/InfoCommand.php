<?php

namespace App\Console\Commands\User;

use App\Console\Command;

class InfoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:info {email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Get info about a user.";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $user = $this->getUser($this->argument('email'), true);

        if (!$user) {
            $this->error('User not found.');
            return 1;
        }

        $props = ['id', 'email', 'created_at', 'updated_at', 'deleted_at'];

        foreach ($props as $prop) {
            if (!empty($user->{$prop})) {
                $this->info("{$prop}: " . $user->{$prop});
            }
        }

        $this->info("status: {$user->status} (" . $user->statusText() . ")");

        if ($user->role) {
            $this->info("role: {$user->role}");
        }

        $user->settings()->orderBy('key')->each(
            function ($setting) {
                if ($setting->value !== null) {
                    $this->info("{$setting->key}: " . \str_replace("\n", ' ', $setting->value));
                }
            }
        );

        // TODO: Display additional info (maybe with --all option):
        // - wallet balance
        // - tenant ID (and name)
        // - if not an account owner, owner ID/email
        // - if signup code available, IP address (other headers)
    }
}
