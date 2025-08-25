<?php

namespace App\Console\Commands\Group;

use App\Console\Command;
use App\Group;
use App\Http\Controllers\API\V4\GroupsController;
use Illuminate\Support\Facades\DB;

/**
 * Create a (mail-enabled) distribution group.
 *
 * @see \App\Console\Commands\Scalpel\Group\CreateCommand
 */
class CreateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'group:create {email} {--member=*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Create a group.";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $email = $this->argument('email');
        $members = $this->option('member');

        [$local, $domainName] = explode('@', $email, 2);

        $domain = $this->getDomain($domainName);

        if (!$domain) {
            $this->error("No such domain {$domainName}.");
            return 1;
        }

        if ($domain->isPublic()) {
            $this->error("Domain {$domainName} is public.");
            return 1;
        }

        $owner = $domain->walletOwner();

        // Validate members addresses
        foreach ($members as $i => $member) {
            if ($error = GroupsController::validateMemberEmail($member, $owner)) {
                $this->error("{$member}: {$error}");
                return 1;
            }
            if (\strtolower($member) === \strtolower($email)) {
                $this->error("{$member}: Cannot be the same as the group address.");
                return 1;
            }
        }

        // Validate group email address
        if ($error = GroupsController::validateGroupEmail($email, $owner)) {
            $this->error("{$email}: {$error}");
            return 1;
        }

        DB::beginTransaction();

        // Create the group
        $group = new Group();
        $group->email = $email;
        $group->tenant_id = $domain->tenant_id;
        $group->save();

        $group->setAddresses($members, true);
        $group->assignToWallet($owner->wallets->first());

        DB::commit();

        $this->info((string) $group->id);
    }
}
