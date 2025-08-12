<?php

namespace App\Console\Commands\Imap;

use App\Console\Command;
use App\Support\Facades\IMAP;

class CopyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:copy {sourceUser} {sourceMailbox} {targetUser} {targetMailbox} {--metadata=*}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Copy IMAP Folder";

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $sourceUser = $this->argument('sourceUser');
        $targetUser = $this->argument('targetUser');
        $sourceMailbox = $this->argument('sourceMailbox');
        $targetMailbox = $this->argument('targetMailbox');

        if ($sourceMailbox == "*") {
            $mailboxes = IMAP::listMailboxes($sourceUser);
            foreach ($mailboxes as $mailbox) {
                IMAP::copyMailbox(
                    $mailbox,
                    IMAP::userMailbox($targetUser, IMAP::folderName($mailbox)),
                    $this->option('metadata')
                );
            }
            IMAP::copyMailbox(
                IMAP::userMailbox($sourceUser, "INBOX"),
                IMAP::userMailbox($targetUser, "INBOX"),
                $this->option('metadata')
            );
        } else {
            IMAP::copyMailbox(
                IMAP::userMailbox($sourceUser, $sourceMailbox),
                IMAP::userMailbox($targetUser, $targetMailbox),
                $this->option('metadata')
            );
        }
    }
}
