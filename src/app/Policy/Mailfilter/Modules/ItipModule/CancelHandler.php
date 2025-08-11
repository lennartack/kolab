<?php

namespace App\Policy\Mailfilter\Modules\ItipModule;

use App\Policy\Mailfilter\MailParser;
use App\Policy\Mailfilter\Modules\ItipModule;
use App\Policy\Mailfilter\Notifications\ItipNotification;
use App\Policy\Mailfilter\Notifications\ItipNotificationParams;
use App\Policy\Mailfilter\Result;
use Sabre\VObject\Component;

class CancelHandler extends ItipModule
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

        $this->parser = $parser;

        // Check whether the event already exists
        $existing = $this->findObject($user, $this->uid, $this->type);

        if (!$existing) {
            return null;
        }

        $existingMaster = $this->extractMainComponent($existing);
        $cancelMaster = $this->extractMainComponent($this->itip);

        if (!$existingMaster || !$cancelMaster) {
            $parser->debug("Failed to get the main component. Ignored.");
            return null;
        }

        // Spoofing protection
        if (!$this->checkOrigin($cancelMaster, $existing)) {
            return null;
        }

        $recurrence_id = (string) $cancelMaster->{'RECURRENCE-ID'};

        if ($recurrence_id) {
            // When we cancel an event occurence we update the main event by removing
            // the exception VEVENT components, and adding EXDATE entries into the master.

            // First find and remove the exception object, if exists
            if ($existingInstance = $this->extractRecurrenceInstanceComponent($existing, $recurrence_id)) {
                // Outdated message, just deliver it, let the MUAs deal with this
                if (!$this->isEligibleForUpdate($cancelMaster, $existingInstance)) {
                    return null;
                }

                $existing->remove($existingInstance);
            }

            // Add the EXDATE entry
            // FIXME: Do we need to handle RECURRENCE-ID differently to get the exception date (timezone)?
            // TODO: We should probably make sure the entry does not exist yet
            $exdate = $cancelMaster->{'RECURRENCE-ID'}->getDateTime();
            $existingMaster->add('EXDATE', $exdate, ['VALUE' => 'DATE'], 'DATE');

            $parser->debug("Updating object at {$this->davLocation}");

            $dav = $this->getDAVClient($user);
            $dav->update($this->toOpaqueObject($existing, $this->davLocation));
        } else {
            // Outdated message, just deliver it, let the MUAs deal with this
            if (!$this->isEligibleForUpdate($cancelMaster, $existingMaster)) {
                return null;
            }

            $existingInstance = $existingMaster;

            $parser->debug("Deleting object at {$this->davLocation}");

            // Remove the event from attendee's calendar
            // Note: We make this the default case because Outlook does not like events with cancelled status
            // optionally we could update the event with STATUS=CANCELLED instead.
            $dav = $this->getDAVClient($user);
            $dav->delete($this->davLocation);
        }

        // Send a notification to the recipient (attendee)
        $parser->debug("Sending notification to {$user->email}");
        $user->notify($this->notification($existingInstance, $cancelMaster->COMMENT));

        // Stop message delivery to the attendee's Inbox
        return new Result(Result::STATUS_DISCARD);
    }

    /**
     * Create a notification
     */
    private function notification(Component $existing, $comment): ItipNotification
    {
        $organizer = $existing->ORGANIZER;

        $params = new ItipNotificationParams('cancel', $existing);
        $params->comment = (string) $comment;
        $params->senderName = (string) $organizer['CN'];
        $params->senderEmail = strtolower(preg_replace('!^mailto:!i', '', (string) $organizer));

        return new ItipNotification($params);
    }
}
