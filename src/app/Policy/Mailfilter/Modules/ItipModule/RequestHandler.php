<?php

namespace App\Policy\Mailfilter\Modules\ItipModule;

use App\Policy\Mailfilter\MailParser;
use App\Policy\Mailfilter\Modules\ItipModule;
use App\Policy\Mailfilter\Result;
use Sabre\VObject\Component;
use Sabre\VObject\Document;

class RequestHandler extends ItipModule
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

        // According to https://datatracker.ietf.org/doc/html/rfc5546#section-3.2.2 REQUESTs are used to:
        // - Invite "Attendees" to an event.
        // - Reschedule an existing event.
        // - Response to a REFRESH request.
        // - Update the details of an existing event, without rescheduling it.
        // - Update the status of "Attendees" of an existing event, without rescheduling it.
        // - Reconfirm an existing event, without rescheduling it.
        // - Forward a "VEVENT" to another uninvited user.
        // - For an existing "VEVENT" calendar component, delegate the role of "Attendee" to another user.
        // - For an existing "VEVENT" calendar component, change the role of "Organizer" to another user.

        // FIXME: This whole method could be async, if we wanted to be more responsive on mail delivery,
        // but CANCEL and REPLY could not, because we're potentially stopping mail delivery there,
        // so I suppose we'll do all of them synchronously for now. Still some parts of it can be async.

        $this->parser = $parser;

        // Check whether the object already exists in the recipient's calendar
        $existing = $this->findObject($user, $this->uid, $this->type);

        // Sanity check
        if (!$this->davFolder) {
            \Log::error("Failed to get any DAV folder for {$user->email}.");
            return null;
        }

        // FIXME: what to do if REQUEST attendees do not match with the recipient email(s)?
        // stop processing here and pass the message to the inbox?

        $requestMaster = $this->extractMainComponent($this->itip);
        $recurrence_id = (string) $requestMaster->{'RECURRENCE-ID'};

        // The event does not exist yet in the recipient's calendar, create it
        if (!$existing) {
            if (!empty($recurrence_id)) {
                $parser->debug("Object does not exist, but it's a recurring instance. Ignored.");
                return null;
            }

            // Create the event in the recipient's calendar
            $dav = $this->getDAVClient($user);
            $object = $this->toOpaqueObject($this->itip);

            $parser->debug("Object does not exist, saving into {$object->href}");

            $dav->create($object);

            return null;
        }

        // TODO: Cover all cases mentioned above

        // FIXME: For updates that don't create a new exception should we replace the iTip with a notification?
        // Or maybe we should not even bother with auto-updating and leave it to MUAs?

        if ($recurrence_id) {
            // Recurrence instance
            $existingInstance = $this->extractRecurrenceInstanceComponent($existing, $recurrence_id);

            // A new recurrence instance, just add it to the existing event
            if (!$existingInstance) {
                // TODO: Bump LAST-MODIFIED on the master object
                $existing->add($requestMaster);
            } else {
                // SEQUENCE does not match, deliver the message, let the MUAs deal with this
                // TODO: A higher SEQUENCE indicates a re-scheduled object, we should update the existing event.
                if ((int) ((string) $existingInstance->SEQUENCE) != (int) ((string) $requestMaster->SEQUENCE)) {
                    return null;
                }

                $this->mergeComponents($existingInstance, $requestMaster);
                // TODO: Bump LAST-MODIFIED on the master object
            }
        } else {
            // Master event
            $existingMaster = $this->extractMainComponent($existing);

            if (!$existingMaster) {
                return null;
            }

            // SEQUENCE does not match, deliver the message, let the MUAs deal with this
            // TODO: A higher SEQUENCE indicates a re-scheduled object, we should update the existing event.
            if ((int) ((string) $existingMaster->SEQUENCE) != (int) ((string) $requestMaster->SEQUENCE)) {
                return null;
            }

            // FIXME: Merge all components included in the request?
            $this->mergeComponents($existingMaster, $requestMaster);
        }

        $parser->debug("Updating object at {$this->davLocation}");

        $dav = $this->getDAVClient($user);
        $dav->update($this->toOpaqueObject($existing, $this->davLocation));

        return null;
    }

    /**
     * Merge VOBJECT component properties into another component
     */
    protected function mergeComponents(Component $to, Component $from): void
    {
        // TODO: Every property? What other properties? EXDATE/RDATE? ORGANIZER? ATTACH?
        // TODO: Removal of RRULE from the master event
        $props = ['SEQUENCE', 'RRULE'];
        foreach ($props as $prop) {
            if (isset($from->{$prop})) {
                $to->{$prop} = $from->{$prop};
            }
        }

        // Replace the list of ATTENDEEs
        $to->remove('ATTENDEE');
        foreach ($from->ATTENDEE ?? [] as $attendee) {
            $class = $attendee::class;
            $to->add(new $class($to->parent, 'ATTENDEE', $attendee->getValue(), $attendee->parameters()));
        }

        // If RRULE contains UNTIL remove exceptions from the timestamp forward
        if (isset($to->RRULE) && ($parts = $to->RRULE->getParts()) && !empty($parts['UNTIL'])) {
            // TODO: Timezone? Also test that with UNTIL using a date format
            $until = new \DateTime($parts['UNTIL']);
            /** @var Document $doc */
            $doc = $to->parent;

            foreach ($doc->getComponents() as $component) {
                if ($component->name == $to->name && !empty($component->{'RECURRENCE-ID'})) {
                    if ($component->{'RECURRENCE-ID'}->getDateTime() >= $until) {
                        $doc->remove($component);
                    }
                }
            }
        }
    }
}
