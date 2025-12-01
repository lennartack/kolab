<?php

namespace App\Backends\DAV;

class SearchFilter implements \Stringable
{
    public $filters = [];

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    /**
     * Create string representation of the search
     *
     * @return string
     */
    public function __toString()
    {
        $filter = '<c:filter>';

        foreach ($this->filters as $sub_filter) {
            $filter .= (string) $sub_filter;
        }

        $filter .= '</c:filter>';

        return $filter;
    }
}
