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
            // FIXME: Should we stop message delivery?
            return null;
        }

        // FIXME: what to do if the REPLY comes from an address not mentioned in the event?
        // FIXME: Should we check if the recipient is an organizer?
        // stop processing here and pass the message to the inbox, or drop it?

        $existingMaster = $this->extractMainComponent($existing);
        $replyMaster = $this->extractMainComponent($this->itip);

        if (!$existingMaster || !$replyMaster) {
            $parser->debug("Failed to get the main component. Ignored.");
            return null;
        }

        // SEQUENCE does not match, deliver the message, let the MUAs to deal with this
        // FIXME: Is this even a valid aproach regarding recurrence?
        if ((string) $existingMaster->SEQUENCE != (string) $replyMaster->SEQUENCE) {
            $parser->debug("Sequence mismatch. Ignored.");
            return null;
        }

        // Per RFC 5546 there can be only one ATTENDEE in REPLY
        if (count($replyMaster->ATTENDEE) != 1) {
            $parser->debug("Too many attendees in REPLY. Ignored.");
            return null;
        }

        // TODO: Delegation

        $sender = $replyMaster->ATTENDEE;
        $partstat = $sender['PARTSTAT'];
        $email = strtolower(preg_replace('!^mailto:!i', '', (string) $sender));

        // Supporting attendees w/o an email address could be considered in the future
        if (empty($email)) {
            $parser->debug("Attendee without an email address. Ignored.");
            return null;
        }

        // Invalid/useless reply, let the MUA deal with it
        // FIXME: Or should we stop delivery?
        if (empty($partstat) || $partstat == 'NEEDS-ACTION') {
            $parser->debug("Unexpected PARTSTAT in REPLY. Ignored.");
            return null;
        }

        $recurrence_id = (string) $replyMaster->{'RECURRENCE-ID'};

        if ($recurrence_id) {
            $existingInstance = $this->extractRecurrenceInstanceComponent($existing, $recurrence_id);
            // No such recurrence exception, let the MUA deal with it
            // FIXME: Or should we stop delivery?
            if (!$existingInstance) {
                $parser->debug("No existing recurrence instance. Ignored.");
                return null;
            }
        } else {
            $existingInstance = $existingMaster;
        }

        // Update organizer's event with attendee status
        $updated = false;
        if (isset($existingInstance->ATTENDEE)) {
            foreach ($existingInstance->ATTENDEE as $attendee) {
                $value = strtolower(preg_replace('!^mailto:!i', '', (string) $attendee));
                if ($value === $email) {
                    if (empty($attendee['PARTSTAT']) || (string) $attendee['PARTSTAT'] != $partstat) {
                        $attendee['PARTSTAT'] = $partstat;
                        $updated = true;
                    }
                }
            }
        }

        if ($updated) {
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
        $params->senderEmail = strtolower(preg_replace('!^mailto:!i', '', (string) $attendee));

        return new ItipNotification($params);
    }
}
