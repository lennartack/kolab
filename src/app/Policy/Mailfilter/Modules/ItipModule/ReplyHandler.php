<?php

namespace App\Policy\Mailfilter\Modules\ItipModule;

use App\Policy\Mailfilter\MailParser;
use App\Policy\Mailfilter\Modules\ItipModule;
use App\Policy\Mailfilter\Notifications\ItipNotification;
use App\Policy\Mailfilter\Notifications\ItipNotificationParams;
use App\Policy\Mailfilter\Result;
use Sabre\VObject\Component;

class ReplyHandler extends ItipModule
{
    protected Component $itip;

    public function __construct(Component $itip, string $type, string $uid)
    {
        $this->itip = $itip;
        $this->type = $type;
        $this->uid = $uid;
    }

    /**
     * Handle the email message
     */
    public function handle(MailParser $parser): ?Result
    {
        $user = $parser->getUser();

        // Accourding to https://datatracker.ietf.org/doc/html/rfc5546#section-3.2.3 REPLY is used to:
        // - respond (e.g., accept or decline) to a "REQUEST"
        // - reply to a delegation "REQUEST"

        // TODO: We might need to use DAV locking mechanism if multiple processes
        // are likely to attempt to update the same event at the same time.

        $this->parser = $parser;

        // Check whether the event already exists
        $existing = $this->findObject($user, $this->uid, $this->type);

        if (!$existing) {
            return null;
        }

        $existingMaster = $this->extractMainComponent($existing);
        $replyMaster = $this->extractMainComponent($this->itip);

        if (!$existingMaster || !$replyMaster) {
            $parser->debug("Failed to get the main component. Ignored.");
            return null;
        }

        // Spoofing protection
        if (!$this->checkOrigin($replyMaster, $existing)) {
            return null;
        }

        // Per https://datatracker.ietf.org/doc/html/rfc5546#section-3.2.3 there can be only
        // one ATTENDEE in a REPLY. However, there are examples for a delegation case that
        // have two ATTENDEEs
        $sender_email = strtolower($parser->getSender());
        $sender = null;
        foreach ($replyMaster->ATTENDEE ?? [] as $attendee) {
            $attendee_email = strtolower(str_ireplace('mailto:', '', (string) $attendee));
            // TODO: Support using of an alias by a local user?
            if ($attendee_email === $sender_email) {
                $sender = $attendee;
            }
        }

        if (empty($sender)) {
            $parser->debug("The sender does not match any ATTENDEE in the REPLY. Ignored.");
            return null;
        }

        // Invalid/useless reply, let the MUA deal with it
        if (in_array($sender['PARTSTAT'] ?? '', ['', 'NEEDS-ACTION'])) {
            $parser->debug("Unexpected PARTSTAT in REPLY. Ignored.");
            return null;
        }

        $recurrence_id = (string) $replyMaster->{'RECURRENCE-ID'};

        if ($recurrence_id) {
            $existingInstance = $this->extractRecurrenceInstanceComponent($existing, $recurrence_id);

            // No such recurrence exception, let the MUA deal with it
            if (!$existingInstance) {
                $parser->debug("No existing recurrence instance. Ignored.");
                return null;
            }
        } else {
            $existingInstance = $existingMaster;
        }

        // Outdated message, just deliver it, let the MUAs deal with this
        if (!$this->isEligibleForUpdate($replyMaster, $existingInstance)) {
            return null;
        }

        if ($this->updateObject($existingInstance, $replyMaster, $sender)) {
            $parser->debug("Updating object at {$this->davLocation}");

            $dav = $this->getDAVClient($user);
            $dav->update($this->toOpaqueObject($existing, $this->davLocation));

            // TODO: We do not update the status in other attendee's calendars. We should consider
            // doing something more standard, send them unsolicited REQUEST in the name of the organizer,
            // as described in https://datatracker.ietf.org/doc/html/rfc5546#section-3.2.2.2.
            // Remove (not deliver) the message to the organizer's inbox

            // Send a notification to the organizer
            $parser->debug("Sending notification to {$user->email}");
            $user->notify($this->notification($existingInstance, $sender, $replyMaster->COMMENT));
        } else {
            $parser->debug("Object unchanged");
        }

        return new Result(Result::STATUS_DISCARD);
    }

    /**
     * Create a notification
     */
    private function notification(Component $existing, $attendee, $comment): ItipNotification
    {
        $params = new ItipNotificationParams('reply', $existing);
        $params->comment = (string) $comment;
        $params->partstat = (string) $attendee['PARTSTAT'];
        $params->senderName = (string) $attendee['CN'];
        $params->senderEmail = strtolower(str_ireplace('mailto:', '', (string) $attendee));

        if ($attendee['DELEGATED-TO']) {
            $names = [];
            $delegates = array_map(
                fn ($item) => str_ireplace('mailto:', '', $item),
                $attendee['DELEGATED-TO']->getParts()
            );

            foreach ($delegates as $delegate) {
                foreach ($existing->ATTENDEE ?? [] as $attendee) {
                    $attendee_email = strtolower(str_ireplace('mailto:', '', (string) $attendee));
                    if ($attendee_email === $delegate && $attendee['CN']) {
                        $names[] = $attendee['CN'];
                    }
                }
            }

            $params->delegateEmail = implode(', ', $delegates);
            $params->delegateName = (count($names) == count($delegates)) ? implode(', ', $names) : '';
        }

        return new ItipNotification($params);
    }

    /**
     * Update the object with attendee state from the reply
     */
    private function updateObject(Component $existing, Component $reply, $sender): bool
    {
        $sender_email = strtolower(str_ireplace('mailto:', '', (string) $sender));
        $updated = false;
        $attendees = [];

        // Update organizer's event with attendee status
        foreach ($existing->ATTENDEE ?? [] as $attendee) {
            $attendee_email = strtolower(str_ireplace('mailto:', '', (string) $attendee));
            $attendees[] = $attendee_email;

            if ($attendee_email === $sender_email) {
                // FIXME: Is there a cleaner way to copy parameters?
                foreach (array_keys($attendee->parameters()) as $key) {
                    unset($attendee[$key]);
                }
                foreach ($sender->parameters() as $key => $value) {
                    $attendee[$key] = $value;
                }
                $updated = true;
            }
        }

        // When an attendee delegates another user he may reply to the organizer
        // with his ATTENDEE property, and optionally the delegatee's ATTENDEE property
        // We make sure to add delegatee's ATTENDEE property to the object (if not exists yet)
        if ($updated && $sender['DELEGATED-TO']) {
            $delegates = array_map(
                fn ($item) => str_ireplace('mailto:', '', $item),
                $sender['DELEGATED-TO']->getParts()
            );

            foreach ($delegates as $delegate) {
                if (!in_array($delegate, $attendees)) {
                    $params = [];
                    foreach ($reply->ATTENDEE as $attendee) {
                        $attendee_email = strtolower(str_ireplace('mailto:', '', (string) $attendee));
                        if ($attendee_email === $delegate) {
                            $params = $attendee->parameters();
                            break;
                        }
                    }

                    if (empty($params)) {
                        $params = [
                            'PARTSTAT' => 'NEEDS-ACTION',
                            'DELEGATED-FROM' => 'mailto:' . $sender_email,
                            'ROLE' => $sender['ROLE'],
                            'CUTYPE' => $sender['CUTYPE'],
                        ];
                    }

                    $existing->add('ATTENDEE', 'mailto:' . $delegate, $params);
                }
            }
        }

        // FIXME: We should probably bump LAST-MODIFIED and/or DTSTAMP property

        return $updated;
    }
}
