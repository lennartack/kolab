<?php

namespace App\Policy\Mailfilter\Modules\ItipModule;

use App\Policy\Mailfilter\MailParser;
use App\Policy\Mailfilter\Modules\ItipModule;
use App\Policy\Mailfilter\Notifications\ItipNotification;
use App\Policy\Mailfilter\Notifications\ItipNotificationParams;
use App\Policy\Mailfilter\Result;
use App\User;
use Sabre\VObject\Component;

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

        $this->parser = $parser;

        // Check whether the object already exists in the recipient's calendar
        $existing = $this->findObject($user, $this->uid, $this->type);

        // Sanity check
        if (!$this->davFolder) {
            \Log::error("Failed to get any DAV folder for {$user->email}.");
            return null;
        }

        $requestMaster = $this->extractMainComponent($this->itip);
        $recurrence_id = (string) $requestMaster->{'RECURRENCE-ID'};

        // Spoofing protection
        if (!$this->checkOrigin($requestMaster, $existing)) {
            return null;
        }

        // If REQUEST attendees do not match with the recipient email(s)
        // stop processing here and pass the message to the Inbox.
        if (!$this->checkRecipient($requestMaster)) {
            $parser->debug("REQUEST attendees do not match recipient's addresses. Ignored.");
            return null;
        }

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

        if ($recurrence_id) {
            // Recurrence instance
            $existingInstance = $this->extractRecurrenceInstanceComponent($existing, $recurrence_id);

            // Outdated message, just deliver it, let the MUAs deal with this
            if (!$this->isEligibleForUpdate($requestMaster, $existingInstance)) {
                return null;
            }

            // Organizer is the event owner, always replace the whole exception with the new one
            if ($existingInstance) {
                $existing->remove($existingInstance);
            }

            $existing->add($requestMaster);
        } else {
            // Master event
            $existingMaster = $this->extractMainComponent($existing);

            // Outdated message, just deliver it, let the MUAs deal with this
            if (!$this->isEligibleForUpdate($requestMaster, $existingMaster)) {
                return null;
            }

            // Organizer is the event owner, always replace the whole event with the new one
            $existing = $this->itip;
            $existingInstance = $existingMaster;
        }

        $parser->debug("Updating object at {$this->davLocation}");

        $dav = $this->getDAVClient($user);
        $dav->update($this->toOpaqueObject($existing, $this->davLocation));

        // If the recipient's action is not required replace the message with a notification
        if (!$this->isActionRequired($requestMaster, $existingInstance)) {
            $parser->debug("Sending notification to {$user->email}");
            $user->notify($this->notification($requestMaster));

            return new Result(Result::STATUS_DISCARD);
        }

        return null;
    }

    /**
     * Check if the request recipient is one of the event attendees
     */
    private function checkRecipient(Component $request): bool
    {
        if (empty($request->ATTENDEE)) {
            return false;
        }

        $user = $this->parser->getUser();
        $attendees = [];

        // Check the main user email address
        foreach ($request->ATTENDEE as $attendee) {
            $email = strtolower(preg_replace('!^mailto:!i', '', (string) $attendee));
            if ($email === $user->email) {
                return true;
            }
            if ($email) {
                $attendees[] = $email;
            }
        }

        // Check the user aliases
        return $user->aliases->whereIn('alias', $attendees)->count() > 0;
    }

    /**
     * Check if attendee action is required for the request
     */
    private function isActionRequired(Component $request, ?Component $existing = null): bool
    {
        if (empty($existing)) {
            return true;
        }

        if ((string) $existing->SEQUENCE < (string) $request->SEQUENCE) {
            return true;
        }

        $user = $this->parser->getUser();

        foreach ($request->ATTENDEE as $attendee) {
            $email = strtolower(preg_replace('!^mailto:!i', '', (string) $attendee));
            if ($email === $user->email || $user->aliases->contains('alias', $email)) {
                if (empty($attendee['PARTSTAT']) || (string) $attendee['PARTSTAT'] == 'NEEDS-ACTION') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Create a notification
     */
    private function notification(Component $request): ItipNotification
    {
        $organizer = $request->ORGANIZER;

        $params = new ItipNotificationParams('request', $request);
        $params->comment = (string) $request->COMMENT;
        $params->senderName = (string) $organizer['CN'];
        $params->senderEmail = strtolower(preg_replace('!^mailto:!i', '', (string) $organizer));

        return new ItipNotification($params);
    }
}
