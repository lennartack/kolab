<?php

namespace App\Console\Commands\Group;

use App\Console\Command;
use App\Http\Controllers\API\V4\GroupsController;

class AddMemberCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'group:add-member {group} {member}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Add a member to a group.";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $input = $this->argument('group');
        $member = \strtolower($this->argument('member'));
        $group = $this->getGroup($input);

        if (empty($group)) {
            $this->error("Group {$input} does not exist.");
            return 1;
        }

        $existing = $group->getAddresses();

        if (in_array($member, $existing)) {
            $this->error("{$member}: Already exists in the group.");
            return 1;
        }

        $owner = $group->walletOwner();

        if ($error = GroupsController::validateMemberEmail($member, $owner)) {
            $this->error("{$member}: {$error}");
            return 1;
        }

        $existing[] = $member;
        $group->setAddresses($existing);
    }
}
