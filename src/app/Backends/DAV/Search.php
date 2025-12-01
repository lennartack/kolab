<?php

namespace App\Backends\DAV;

use App\Backends\DAV;

class Search implements \Stringable
{
    public $component;

    public $depth = 1;

    public $dataProperties = [];

    public $hrefs = [];

    public $properties = [];

    public $withContent = false;

    public $filters = [];

    /** @var bool Is it a multiget report or a query? */
    public $is_report = false;

    public function __construct($component, $withContent = false, $filters = [], $is_report = false)
    {
        $this->component = $component;
        $this->withContent = $withContent;
        $this->filters = $filters;
        $this->is_report = $is_report;
    }

    /**
     * Create string representation of the search
     *
     * @return string
     */
    public function __toString()
    {
        $ns = implode(' ', [
            'xmlns:d="DAV:"',
            'xmlns:c="' . DAV::NAMESPACES[$this->component] . '"',
        ]);

        $hrefs = '';
        foreach ($this->hrefs as $href) {
            $hrefs .= '<d:href>' . $href . '</d:href>';
        }

        // Return properties
        $props = [];
        foreach ($this->properties as $prop) {
            $props[] = '<' . $prop . '/>';
        }

        // Warning: Looks like some servers (iRony) ignore address-data/calendar-data
        // and return full VCARD/VCALENDAR. Which leads to unwanted loads of data in a response.
        if (!empty($this->dataProperties)) {
            $more_props = [];
            foreach ($this->dataProperties as $prop) {
                $more_props[] = '<c:prop name="' . $prop . '"/>';
            }

            if ($this->component == DAV::TYPE_VCARD) {
                $props[] = '<c:address-data>' . implode('', $more_props) . '</c:address-data>';
            } else {
                $props[] = '<c:calendar-data><c:comp name="VCALENDAR">'
                        . '<c:prop name="VERSION"/>'
                        . '<c:prop name="PRODID"/>'
                        . '<c:comp name="' . $this->component . '">' . implode('', $more_props) . '</c:comp>'
                    . '</c:comp></c:calendar-data>';
            }
        } elseif ($this->withContent) {
            if ($this->component == DAV::TYPE_VCARD) {
                $props[] = '<c:address-data/>';
            } else {
                $props[] = '<c:calendar-data/>';
            }
        }

        // Search filter
        $filters = $this->filters;
        if ($this->component == DAV::TYPE_VCARD) {
            $query = $this->is_report ? 'addressbook-multiget' : 'addressbook-query';
        } else {
            $query = $this->is_report ? 'calendar-multiget' : 'calendar-query';
            array_unshift($filters, new SearchCompFilter('VCALENDAR', [new SearchCompFilter($this->component)]));
        }

        if (!$this->is_report) {
            $filter = new SearchFilter($filters);
        } else {
            $filter = '';
        }

        if (empty($props)) {
            $props = '<d:allprop/>';
        } else {
            $props = '<d:prop>' . implode('', $props) . '</d:prop>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . "<c:{$query} {$ns}>" . $hrefs . $props . $filter . "</c:{$query}>";
    }
}
