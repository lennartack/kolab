<?php

namespace App\Policy\Mailfilter\Modules;

use App\Auth\Utils;
use App\Backends\DAV;
use App\Policy\Mailfilter\MailParser;
use App\Policy\Mailfilter\Module;
use App\Policy\Mailfilter\Result;
use App\Support\Facades\DAV as DAVFacade;
use App\User;
use Sabre\VObject\Component;
use Sabre\VObject\Document;
use Sabre\VObject\Reader;

class ItipModule extends Module
{
    protected $davClient;
    protected $davFolder;
    protected $davLocation;
    protected $davTokenExpiresOn;
    protected $davTTL = 10;

    /** @var MailParser Mail parser instance for the current message */
    protected $parser;

    /** @var string Processed object type ('VEVENT' or 'VTODO') */
    protected $type;

    /** @var string Processed object UID property */
    protected $uid;

    /**
     * Handle the email message
     */
    public function handle(MailParser $parser): ?Result
    {
        $itip = self::getItip($parser);

        if ($itip === null) {
            $parser->debug("No iTip in the message");
            return null; // do nothing
        }

        // TODO: Get the user's invitation policy

        $vobject = $this->parseICal($itip);

        if ($vobject === null) {
            $parser->debug("No supported iCalendar data in the iTip");
            return null; // do nothing
        }

        $this->parser = $parser;

        // Note: Some iTip handling implementation can be find in vendor/sabre/vobject/lib/ITip/Broker.php,
        // however I think we need something more sophisticated that we can extend ourselves.

        // FIXME: If $vobject->METHOD is empty fallback to 'method' param from the Content-Type header?
        // rfc5545#section-3.7.2 says if one is specified the other must be too
        // @phpstan-ignore-next-line
        $method = \strtoupper((string) $vobject->METHOD);
        switch ($method) {
            case 'REQUEST':
                $parser->debug("Parsing an iTip REQUEST...");
                $handler = new ItipModule\RequestHandler($vobject, $this->type, $this->uid);
                break;
            case 'CANCEL':
                $parser->debug("Parsing an iTip CANCEL...");
                $handler = new ItipModule\CancelHandler($vobject, $this->type, $this->uid);
                break;
            case 'REPLY':
                $parser->debug("Parsing an iTip REPLY...");
                $handler = new ItipModule\ReplyHandler($vobject, $this->type, $this->uid);
                break;
            default:
                $parser->debug("Unsupported iTip method: {$method}. iTip ignored.");
        }

        // FIXME: Should we handle (any?) errors silently and just deliver the message to Inbox as a fallback?
        if (!empty($handler)) {
            return $handler->handle($parser);
        }

        return null;
    }

    /**
     * Get the main event/task from the VCALENDAR object
     */
    protected static function extractMainComponent(Component $vobject): ?Component
    {
        foreach ($vobject->getComponents() as $component) {
            if ($component->name == 'VEVENT' || $component->name == 'VTODO') {
                if (empty($component->{'RECURRENCE-ID'})) {
                    return $component;
                }
            }
        }

        // If no recurrence-instance components were found, return any
        foreach ($vobject->getComponents() as $component) {
            if ($component->name == 'VEVENT' || $component->name == 'VTODO') {
                return $component;
            }
        }

        return null;
    }

    /**
     * Get specific event/task recurrence instance from the VCALENDAR object
     */
    protected static function extractRecurrenceInstanceComponent(Component $vobject, string $recurrence_id): ?Component
    {
        foreach ($vobject->getComponents() as $component) {
            if ($component->name == 'VEVENT' || $component->name == 'VTODO') {
                if ((string) $component->{'RECURRENCE-ID'} === $recurrence_id) {
                    return $component;
                }
            }
        }

        return null;
    }

    /**
     * Find an event in user calendar
     */
    protected function findObject(User $user, $uid, $dav_type): ?Component
    {
        if ($uid === null || $uid === '') {
            return null;
        }

        $this->parser->debug("Searching for DAV object (UID={$uid}, User={$user->email})...");

        $dav = $this->getDAVClient($user);
        $filters = [new DAV\SearchPropFilter('UID', DAV\SearchPropFilter::MATCH_EQUALS, $uid)];
        $search = new DAV\Search($dav_type, true, $filters);

        foreach ($dav->listFolders($dav_type) as $folder) {
            // No delegation yet, we skip other users' folders
            if ($folder->owner !== $user->email) {
                continue;
            }

            // Skip schedule inbox/outbox
            if (in_array('schedule-inbox', $folder->types) || in_array('schedule-outbox', $folder->types)) {
                continue;
            }

            // TODO: This default folder detection is kinda silly, but this is what we do in other places
            if ($this->davFolder === null || preg_match('~/(Default|Tasks)/?$~', $folder->href)) {
                $this->davFolder = $folder;
            }

            $this->parser->debug("Searching in {$folder->href}...");

            foreach ($dav->search($folder->href, $search, null, true) as $event) {
                if ($vobject = $this->parseICal((string) $event)) {
                    $this->davLocation = $event->href;
                    $this->davFolder = $folder;

                    $this->parser->debug("Object found: {$this->davLocation}");

                    return $vobject;
                }
            }
        }

        $this->parser->debug("Object not found");

        return null;
    }

    /**
     * Get DAV client
     */
    protected function getDAVClient(User $user): DAV
    {
        // Use short-lived token to authenticate as user
        if (!$this->davTokenExpiresOn || now()->greaterThanOrEqualTo($this->davTokenExpiresOn)) {
            $password = Utils::tokenCreate((string) $user->id, $this->davTTL);

            $this->davTokenExpiresOn = now()->addSeconds($this->davTTL - 1);
            $this->davClient = DAVFacade::getInstance($user->email, $password);
        }

        return $this->davClient;
    }

    /**
     * Check if the message contains an iTip content and get it
     */
    protected static function getItip($parser): ?string
    {
        $calendar_types = ['text/calendar', 'text/x-vcalendar', 'application/ics'];
        $message_type = $parser->getContentType();

        if (in_array($message_type, $calendar_types)) {
            return $parser->getBody();
        }

        // Return early, so we don't have to parse the message
        if (!in_array($message_type, ['multipart/mixed', 'multipart/alternative'])) {
            return null;
        }

        // Find the calendar part (only top-level parts for now)
        foreach ($parser->getParts() as $part) {
            // TODO: Apple sends files as application/x-any (!?)
            // ($mimetype == 'application/x-any' && !empty($filename) && preg_match('/\.ics$/i', $filename))
            if (in_array($part->getContentType(), $calendar_types)) {
                return $part->getBody();
            }
        }

        return null;
    }

    /**
     * Parse an iTip content
     */
    protected function parseICal($ical): ?Document
    {
        $vobject = Reader::read($ical, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);

        if ($vobject->name != 'VCALENDAR') {
            return null;
        }

        foreach ($vobject->getComponents() as $component) {
            // TODO: VTODO
            if ($component->name == 'VEVENT') {
                if ($this->uid === null) {
                    $this->uid = (string) $component->uid;
                    $this->type = (string) $component->name;

                // TODO: We should probably sanity check the VCALENDAR content,
                // e.g. we should ignore/remove all components with UID different then the main (first) one.
                // In case of some obvious issues, delivering the message to inbox is probably safer.
                } elseif ((string) $component->uid != $this->uid) {
                    continue;
                }

                return $vobject;
            }
        }

        return null;
    }

    /**
     * Prepare VCALENDAR object for submission to DAV
     */
    protected function toOpaqueObject(Component $vobject, $location = null): DAV\Opaque
    {
        // Cleanup
        $vobject->remove('METHOD');

        // Create an opaque object
        $object = new DAV\Opaque($vobject->serialize());
        $object->contentType = 'text/calendar; charset=utf-8';
        $object->href = $location;

        // no location? then it's a new object
        if (!$location) {
            $object->href = trim($this->davFolder->href, '/') . '/' . urlencode($this->uid) . '.ics';
        }

        return $object;
    }

    /**
     * Check iTip message origin. Spoofing detection
     *
     * @param Component  $request  iTip content
     * @param ?Component $existing Existing object
     */
    protected function checkOrigin(Component $request, ?Component $existing): bool
    {
        $method = strtoupper(str_replace('Handler', '', class_basename(static::class)));
        $sender = strtolower($this->parser->getSender());

        // First we check if the envelope sender matches the ORGANIZER/ATTENDEE in the iTip
        // TODO: Support a local user using one of his aliases?
        switch ($method) {
            case 'REQUEST':
            case 'CANCEL':
                $property = 'ORGANIZER';
                $email = strtolower(str_ireplace('mailto:', '', (string) $request->ORGANIZER));
                $result = $email === $sender;
                break;
            case 'REPLY':
                $property = 'ATTENDEE';
                // Per https://datatracker.ietf.org/doc/html/rfc5546#section-3.2.3 there can be only
                // one ATTENDEE in a REPLY. However, there are examples for a delegation case that
                // have two ATTENDEEs
                $result = false;
                foreach ($request->ATTENDEE ?? [] as $attendee) {
                    $attendee_email = strtolower(str_ireplace('mailto:', '', (string) $attendee));
                    if ($attendee_email === $sender) {
                        $result = true;
                        $email = $attendee_email;
                        break 2;
                    }
                }
                if (count($request->ATTENDEE) == 1) {
                    $email = str_ireplace('mailto:', '', (string) $request->ATTENDEE);
                }
                if (empty($email)) {
                    return false;
                }
                break;
            default:
                throw new \Exception("Unexpected iTip method: {$method}");
        }

        // Check if this ORGANIZER/ATTENDEE matches the one in the existing object
        // Note: According to https://datatracker.ietf.org/doc/html/rfc5546 (3.2.2.4, 3.2.2.5)
        // change of ORGANIZER is possible, but there's no way to do anything about it.
        // In such a case passing the iTip to the recipient's Inbox is probably the best we can do.
        // The same applies to uninvited users https://datatracker.ietf.org/doc/html/rfc5546 (3.2.2.6).
        if ($result && $existing) {
            $rid = (string) $request->{'RECURRENCE-ID'};
            $master = $this->extractMainComponent($existing);
            $occurence = $rid ? $this->extractRecurrenceInstanceComponent($existing, $rid) : null;

            switch ($method) {
                case 'REQUEST':
                case 'CANCEL':
                    $source = $occurence && !empty($occurence->ORGANIZER) ? $occurence : $master;
                    $organizer = str_ireplace('mailto:', '', (string) $source->ORGANIZER);

                    $result = strtolower($organizer) === $email;
                    break;
                case 'REPLY':
                    $source = $occurence && !empty($occurence->ATTENDEE) ? $occurence : $master;
                    $attendees = self::getAttendeeEmails($source);

                    $result = in_array($email, $attendees);
                    break;
            }
        }

        // Check if this is a delegation request, where an ATTENDEE sends it to a delegatee
        if (!$result && !$existing && $method == 'REQUEST') {
            foreach ($request->ATTENDEE ?? [] as $attendee) {
                $email = str_ireplace('mailto:', '', (string) $attendee);
                // TODO: Check if DELEGATED-TO matches the iTip recipient
                if ($email == $sender && !empty($attendee['DELEGATED-TO'])) {
                    $result = true;
                    break;
                }
            }
        }

        if (!$result) {
            \Log::warning("Itip {$method} origin mismatch for {$property}.");
        }

        return $result;
    }

    /**
     * Get email addresses of all attendees, including delegatees, in a component
     */
    private static function getAttendeeEmails(Component $object): array
    {
        $attendees = [];

        foreach ($object->ATTENDEE ?? [] as $attendee) {
            if ($email = str_ireplace('mailto:', '', (string) $attendee)) {
                $attendees[] = $email;
            }

            if (!empty($attendee['DELEGATED-TO'])) {
                $delegates = array_map(
                    fn ($item) => str_ireplace('mailto:', '', $item),
                    $attendee['DELEGATED-TO']->getParts()
                );

                $attendees = array_merge($attendees, $delegates);
            }
        }

        return array_map('strtolower', $attendees);
    }

    /**
     * Check if the iTip message is eligible for an auto-update.
     *
     * @param Component  $request  iTip content
     * @param ?Component $existing Existing object
     */
    protected function isEligibleForUpdate(Component $request, ?Component $existing = null): bool
    {
        if ($existing === null) {
            return true;
        }

        // SEQUENCE does not match, we'll let the MUAs deal with this
        if ((string) $existing->SEQUENCE > (string) $request->SEQUENCE) {
            $this->parser->debug("Sequence mismatch. Ignored.");
            return false;
        }

        // FIXME: When SEQUENCEs are equal we should compare DTSTAMP. Should we?
        // https://datatracker.ietf.org/doc/html/rfc5546#section-2.1.4
        // https://datatracker.ietf.org/doc/html/rfc5546#section-5.3

        return true;
    }
}
