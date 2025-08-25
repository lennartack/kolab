<?php

namespace App\Console\Commands\Group;

use App\Console\Command;

class InfoCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'group:info {group}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Print a group information.";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $input = $this->argument('group');
        $group = $this->getGroup($input);

        if (empty($group)) {
            $this->error("Group {$input} does not exist.");
            return 1;
        }

        $this->info('Id: ' . $group->id);
        $this->info('Email: ' . $group->email);
        $this->info('Status: ' . $group->status);

        // TODO: Print owner/wallet

        foreach ($group->getAddresses() as $member) {
            $this->info('Member: ' . $member);
        }
    }
}
