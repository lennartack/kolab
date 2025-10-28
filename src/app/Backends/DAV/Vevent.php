<?php

namespace App\Backends\DAV;

use Illuminate\Support\Str;
use Sabre\VObject\Component;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;
use Sabre\VObject\Writer;

class Vevent extends CommonObject
{
    /** @var string Object content type (of the string representation) */
    public $contentType = 'text/calendar; charset=utf-8';

    public $attendees = [];
    public $class;
    public $comment;
    public $description;
    public $exceptions = [];
    public $exdate = [];
    public $location;
    public $method;
    public $organizer;
    public $priority;
    public $prodid;
    public $recurrenceId;
    public $rdate = [];
    public $rrule = [];
    public $sequence;
    public $status;
    public $summary;
    public $transp;
    public $url;
    public $valarms = [];

    public $dtstart;
    public $dtend;
    public $created;
    public $lastModified;
    public $dtstamp;

    private $vobject;

    /**
     * Create event object from a DOMElement element
     *
     * @param \DOMElement $element DOM element with object properties
     *
     * @return CommonObject
     */
    public static function fromDomElement(\DOMElement $element)
    {
        /** @var self $object */
        $object = parent::fromDomElement($element);

        if ($data = $element->getElementsByTagName('calendar-data')->item(0)) {
            $object->fromIcal($data->nodeValue);
        }

        return $object;
    }

    /**
     * Set object properties from an iCalendar
     *
     * @param string $ical iCalendar string
     */
    protected function fromIcal(string $ical): void
    {
        $this->vobject = Reader::read($ical, Reader::OPTION_FORGIVING | Reader::OPTION_IGNORE_INVALID_LINES);

        if ($this->vobject->name != 'VCALENDAR') {
            return;
        }

        $selfType = strtoupper(class_basename(static::class));

        if (!empty($this->vobject->PRODID)) {
            $this->prodid = (string) $this->vobject->PRODID;
        }

        if (!empty($this->vobject->METHOD)) {
            $this->method = (string) $this->vobject->METHOD;
        }

        $hasMaster = null;

        foreach ($this->vobject->getComponents() as $component) {
            if ($component->name == $selfType) {
                if (empty($hasMaster) && empty($component->{'RECURRENCE-ID'})) {
                    $this->fromVObject($component);
                    $hasMaster = true;
                } elseif ($this->uid && $this->uid == $component->uid && !empty($component->{'RECURRENCE-ID'})) {
                    $exception = new static(); // @phpstan-ignore-line
                    $exception->fromVObject($component);
                    $this->exceptions[] = $exception;
                }
            }
        }
    }

    /**
     * Set object properties from a Sabre/VObject component object
     *
     * @param Component $vobject Sabre/VObject component
     */
    public function fromVObject(Component $vobject): void
    {
        $string_properties = [
            'CLASS',
            'COMMENT',
            'DESCRIPTION',
            'LOCATION',
            'PRIORITY',
            'RECURRENCE-ID',
            'SEQUENCE',
            'STATUS',
            'SUMMARY',
            'TRANSP',
            'UID',
            'URL',
        ];

        // map string properties
        foreach ($string_properties as $prop) {
            if (isset($vobject->{$prop})) {
                $key = Str::camel(strtolower($prop));
                $this->{$key} = (string) $vobject->{$prop};
            }
        }

        // map other properties
        foreach ($vobject->children() as $prop) {
            if (!$prop instanceof Property) {
                continue;
            }

            switch ($prop->name) {
                case 'DTSTART':
                case 'DTEND':
                case 'CREATED':
                case 'LAST-MODIFIED':
                case 'DTSTAMP':
                    $key = Str::camel(strtolower($prop->name));
                    // These are of type Sabre\VObject\Property\ICalendar\DateTime
                    $this->{$key} = $prop;
                    break;
                case 'RRULE':
                    $params = [];

                    foreach ($prop->getParts() as $k => $v) {
                        $params[Str::camel(strtolower($k))] = is_array($v) ? implode(',', $v) : $v;
                    }

                    if (!empty($params['until'])) {
                        $params['until'] = new \DateTime($params['until']);
                    }

                    if (empty($params['interval'])) {
                        $params['interval'] = 1;
                    }

                    $this->rrule = array_filter($params);
                    break;
                case 'EXDATE':
                case 'RDATE':
                    $key = strtolower($prop->name);
                    $this->{$key}[] = $prop;
                    break;
                case 'ATTENDEE':
                case 'ORGANIZER':
                    $attendee = [
                        'rsvp' => false,
                        'email' => preg_replace('!^mailto:!i', '', (string) $prop),
                    ];

                    $attendeeProps = ['CN', 'PARTSTAT', 'ROLE', 'CUTYPE', 'RSVP', 'DELEGATED-FROM', 'DELEGATED-TO',
                        'SCHEDULE-STATUS', 'SCHEDULE-AGENT', 'SENT-BY'];

                    foreach ($prop->parameters() as $name => $value) {
                        $key = Str::camel(strtolower($name));
                        switch ($name) {
                            case 'RSVP':
                                $attendee[$key] = strtolower($value) == 'true';
                                break;
                            case 'CN':
                                $attendee[$key] = str_replace('\,', ',', (string) $value);
                                break;
                            case 'SENT-BY':
                            case 'DELEGATED-TO':
                            case 'DELEGATED-FROM':
                                $attendee[$key] = preg_replace(['!^mailto:!i', '!"!'], '', (string) $value);
                                break;
                            default:
                                if (in_array($name, $attendeeProps)) {
                                    $attendee[$key] = (string) $value;
                                }
                                break;
                        }
                    }

                    if ($prop->name == 'ORGANIZER') {
                        $attendee['role'] = 'ORGANIZER';
                        $attendee['partstat'] = 'ACCEPTED';

                        $this->organizer = $attendee;
                    } elseif (empty($this->organizer) || $attendee['email'] != $this->organizer['email']) {
                        $this->attendees[] = $attendee;
                    }

                    break;
                default:
                    if (\str_starts_with($prop->name, 'X-')) {
                        $this->custom[$prop->name] = (string) $prop;
                    }
            }
        }

        // Check DURATION property if no end date is set
        /*
        if (empty($this->dtend) && !empty($this->dtstart) && !empty($vobject->DURATION)) {
            try {
                $duration = new \DateInterval((string) $vobject->DURATION);
                $end = clone $this->dtstart;
                $end->add($duration);
                $this->dtend = $end;
            }
            catch (\Exception $e) {
                // TODO: Error?
            }
        }
        */

        // Find alarms
        foreach ($vobject->select('VALARM') as $valarm) {
            $action = 'DISPLAY';
            $trigger = null;
            $alarm = [];

            foreach ($valarm->children() as $prop) {
                $value = (string) $prop;

                switch ($prop->name) {
                    case 'TRIGGER':
                        foreach ($prop->parameters as $param) {
                            if ($param->name == 'VALUE' && $param->getValue() == 'DATE-TIME') {
                                $trigger = '@' . $prop->getDateTime()->format('U');
                                $alarm['trigger'] = $prop->getDateTime();
                            } elseif ($param->name == 'RELATED') {
                                $alarm['related'] = $param->getValue();
                            }
                        }
                        /*
                                            if (!$trigger && ($values = libcalendaring::parse_alarm_value($value))) {
                                                $trigger = $values[2];
                                            }
                        */
                        if (empty($alarm['trigger'])) {
                            $alarm['trigger'] = rtrim(preg_replace('/([A-Z])0[WDHMS]/', '\1', $value), 'T');
                            // if all 0-values have been stripped, assume 'at time'
                            if ($alarm['trigger'] == 'P') {
                                $alarm['trigger'] = 'PT0S';
                            }
                        }
                        break;
                    case 'ACTION':
                        $action = $alarm['action'] = strtoupper($value);
                        break;
                    case 'SUMMARY':
                    case 'DESCRIPTION':
                    case 'DURATION':
                        $alarm[strtolower($prop->name)] = $value;
                        break;
                    case 'REPEAT':
                        $alarm['repeat'] = (int) $value;
                        break;
                    case 'ATTENDEE':
                        $alarm['attendees'][] = preg_replace('!^mailto:!i', '', $value);
                        break;
                }
            }

            if ($action != 'NONE') {
                if (!empty($alarm['trigger'])) {
                    $this->valarms[] = $alarm;
                }
            }
        }
    }

    /**
     * Make the item compatible with standards (and Cyrus DAV) by fixing
     * obvious issues, if possible
     */
    public function repair(): void
    {
        if (!$this->vobject || $this->vobject->name != 'VCALENDAR') {
            return;
        }

        // TODO: We apply changes to $this->vobject. We should do the same with $this, but
        // we can ignore that until we have support for it in __toString().

        $selfType = strtoupper(class_basename(static::class));
        $master = null;
        $exceptions = [];

        foreach ($this->vobject->getComponents() as $component) {
            if ($component->name == $selfType) {
                // DTEND MUST be later than DTSTART (RFC5545 3.8.2.2)
                if ($component->DTSTART->getDateTime() == $component->DTEND->getDateTime()) {
                    unset($component->DTEND);
                }
                if (empty($master) && empty($component->{'RECURRENCE-ID'})) {
                    $master = $component;
                } elseif ($this->uid && $this->uid == $component->UID && !empty($component->{'RECURRENCE-ID'})) {
                    $exceptions[] = $component;
                }
            }
        }

        // Event exceptions cannot change the organizer
        // We reset it to the original organizer, and make the user an attendee with role=chair.
        if ($master && !empty($exceptions)) {
            foreach ($exceptions as $exception) {
                if ($exception->ORGANIZER && (string) $exception->ORGANIZER != (string) $master->ORGANIZER) {
                    $chair = $exception->ORGANIZER;
                    $exception->ORGANIZER = $master->ORGANIZER;
                    $attendee = null;

                    if ($exception->ATTENDEE) {
                        foreach ($exception->ATTENDEE as $_attendee) {
                            if ((string) $_attendee == (string) $chair) {
                                $attendee = $_attendee;
                                break;
                            }
                        }
                    }

                    if (!$attendee) {
                        $attendee = $exception->add('ATTENDEE', (string) $chair, $chair->parameters());
                        $attendee['PARTSTAT'] = 'ACCEPTED';
                    }

                    $attendee['ROLE'] = 'CHAIR';
                }
            }
        }
    }

    /**
     * Create string representation of the DAV object (iCalendar)
     *
     * @return string
     */
    public function __toString()
    {
        if (!$this->vobject) {
            // TODO we currently can only serialize a message back that we just read
            throw new \Exception("Writing from properties is not implemented");
        }

        return Writer::write($this->vobject);
    }
}
