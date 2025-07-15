<?php

namespace App\Console\Commands\User;

use App\Console\Command;
use App\Support\Facades\IMAP;
use App\Support\Facades\Roundcube;
use Illuminate\Support\Facades\DB;

class ForceDeleteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:force-delete {user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a user for realz';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $user = $this->getUser($this->argument('user'), true);

        if (!$user) {
            $this->error("User not found.");
            return 1;
        }

        if (!$user->trashed()) {
            $this->error("The user is not yet deleted.");
            return 1;
        }

        DB::beginTransaction();
        $user->forceDelete();
        DB::commit();

        // Make sure the mailbox got deleted
        if (IMAP::verifyAccount($user->email)) {
            $this->output->write('The mailbox still exists. Deleting... ');
            if (IMAP::deleteUser($user)) {
                $this->info('DONE');
            } else {
                $this->warn('FAILED');
            }
        }

        // Make sure Roundcube user record is deleted
        if (\config('database.connections.roundcube')) {
            Roundcube::deleteUser($user->email);
        }
    }
}
