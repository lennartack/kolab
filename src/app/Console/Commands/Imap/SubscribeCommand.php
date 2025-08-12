<?php

namespace App\Console\Commands\Imap;

use App\Console\Command;
use App\Support\Facades\IMAP;

class SubscribeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:subscribe {user} {mailbox} {--clear : Clear the subscription state instead}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Subscribe IMAP Folders";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $user = $this->argument('user');
        $mailbox = $this->argument('mailbox');
        if ($mailbox == "*") {
            $mailboxes = IMAP::listMailboxes($user);
        } else {
            $mailboxes = [$mailbox];
        }
        foreach ($mailboxes as $mailbox) {
            if ($this->option('clear')) {
                IMAP::unsubscribeMailbox($user, IMAP::folderName($mailbox));
            } else {
                IMAP::subscribeMailbox($user, IMAP::folderName($mailbox));
            }
        }
    }
}
