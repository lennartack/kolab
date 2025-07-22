<?php

namespace App\Console\Commands\Imap;

use App\Console\Command;
use App\Support\Facades\IMAP;

class ListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:list {user} {--groupware}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "List IMAP Folders";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $user = $this->argument('user');
        if ($this->option("groupware")) {
            foreach (IMAP::listGroupwareMailboxes($user) as $mailbox) {
                $this->info("{$mailbox}");
            }
        } else {
            foreach (IMAP::listMailboxes($user) as $mailbox) {
                $this->info("{$mailbox}");
            }
        }
    }
}
