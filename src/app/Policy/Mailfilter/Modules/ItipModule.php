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
}
