<?php

namespace App\Console\Commands\Ldap;

use App\Console\Command;
use App\Support\Facades\LDAP;

class GroupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ldap:group {action} {group}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Execute actions on the ldap backend for a group";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $action = $this->argument('action');
        $group = $this->getGroup($this->argument('group'));

        if (!$group) {
            $this->error("Group not found.");
            return 1;
        }

        if ($action == "create") {
            LDAP::createGroup($group);
        }
        if ($action == "update") {
            LDAP::updateGroup($group);
        }
        if ($action == "delete") {
            LDAP::deleteGroup($group);
        }
    }
}
