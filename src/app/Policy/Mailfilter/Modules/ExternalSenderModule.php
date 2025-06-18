<?php

namespace App\Policy\Mailfilter\Modules;

use App\Policy\Mailfilter\MailParser;
use App\Policy\Mailfilter\Module;
use App\Policy\Mailfilter\Result;
use App\User;

class ExternalSenderModule extends Module
{
    /**
     * Handle the email message
     */
    public function handle(MailParser $parser): ?Result
    {
        $sender = $parser->getSender();
        $user = $parser->getUser();

        if ($this->isExternalSender($sender, $user)) {
            $parser->debug("{$sender} is an external sender");

            $subject = $parser->getHeader('subject');

            // Update the subject with a prefix
            if (is_string($subject)) {
                $subject = '[EXTERNAL] ' . $subject;
            } else {
                $subject = '[EXTERNAL]';
            }

            $parser->setHeader('Subject', $subject);
        } else {
            $parser->debug("{$sender} is an internal sender");
        }

        return null;
    }

    /**
     * Check if the sender is external for the user's account
     */
    private function isExternalSender($sender, User $user): bool
    {
        [, $sender_domain] = explode('@', $sender);
        [, $user_domain] = explode('@', $user->email);

        $sender_domain = strtolower($sender_domain);

        // Sender and recipient in the same domain
        if ($sender_domain === $user_domain) {
            return false;
        }

        // Check domain against a per-account list of additional domains
        if (!empty($this->config['externalsender_policy_domains'])
            && in_array($sender_domain, $this->config['externalsender_policy_domains'])
        ) {
            return false;
        }

        $account = $user->walletOwner();

        // Check against the account domains list
        if ($account && $account->domains(false, false)->where('namespace', $sender_domain)->exists()) {
            return false;
        }

        return true;
    }
}
