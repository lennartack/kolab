<?php

namespace App\Console\Commands\Ldap;

use App\Console\Command;
use App\Support\Facades\LDAP;

class DomainCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ldap:domain {action} {domain}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Execute actions on the ldap backend for a domain";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $action = $this->argument('action');
        $domain = $this->getDomain($this->argument('domain'));

        if (!$domain) {
            $this->error("Domain not found.");
            return 1;
        }

        if ($action == "create") {
            LDAP::createDomain($domain);
        }
        if ($action == "update") {
            LDAP::updateDomain($domain);
        }
        if ($action == "delete") {
            LDAP::deleteDomain($domain);
        }
    }
}
