<?php

namespace App\Console\Commands\Ldap;

use App\Console\Command;
use App\Support\Facades\LDAP;

class UserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ldap:user {action} {user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Execute actions on the ldap backend for a user";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $action = $this->argument('action');
        $user = $this->getUser($this->argument('user'));

        if (!$user) {
            $this->error("User not found.");
            return 1;
        }

        if ($action == "create") {
            LDAP::createUser($user);
        }
        if ($action == "update") {
            LDAP::updateUser($user);
        }
        if ($action == "delete") {
            LDAP::deleteUser($user);
        }
    }
}
