<?php

namespace App\Policy\Mailfilter\Modules;

use App\Policy\Mailfilter\MailParser;
use App\Policy\Mailfilter\Module;
use App\Policy\Mailfilter\Result;

class TestModule extends Module
{
    /**
     * Handle the email message
     */
    public function handle(MailParser $parser): ?Result
    {
        $subject = $parser->getHeader('subject');
        if (str_starts_with($subject, "KOLABv4TestMessage")) {
            $parser->debug("Received a test message: {$subject}");
            if (str_contains($subject, "DUMP")) {
                $str = $parser->dumpStream();
                $str = str_replace("\r", "CR", $str);
                $str = str_replace("\n", "LF\n", $str);
                $parser->debug($str);
            }

            if (str_contains($subject, "MODIFYSUBJECT")) {
                $parser->setHeader('Subject', $subject . " MODIFIED");
            }
        }

        return null;
    }
}
