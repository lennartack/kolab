<?php

namespace App\Backends\DAV;

class SearchCompFilter implements \Stringable
{
    public $name;
    public $filters = [];

    public function __construct($name, $filters = [])
    {
        $this->name = $name;
        $this->filters = $filters;
    }

    /**
     * Create string representation of the prop-filter
     *
     * @return string
     */
    public function __toString()
    {
        $filter = '<c:comp-filter name="' . $this->name . '"';

        if (empty($this->filters)) {
            $filter .= '/>';
        } else {
            $filter .= '>';

            foreach ($this->filters as $sub_filter) {
                $filter .= (string) $sub_filter;
            }

            $filter .= '</c:comp-filter>';
        }

        return $filter;
    }
}
