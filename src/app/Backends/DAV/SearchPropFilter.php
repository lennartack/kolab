<?php

namespace App\Backends\DAV;

class SearchPropFilter implements \Stringable
{
    public const IS_NOT_DEFINED = 'is-not-defined';

    public const MATCH_EQUALS = 'equals';
    public const MATCH_CONTAINS = 'contains';
    public const MATCH_STARTS_WITH = 'starts-with';
    public const MATCH_ENDS_WITH = 'ends-with';

    public $name;
    public $type;
    public $collation;
    public $negate = false;
    public $value;

    public function __construct(string $name, string $type, ?string $value = null, ?string $collation = null)
    {
        $this->name = $name;
        $this->type = $type;
        $this->value = $value;
        $this->collation = $collation;
    }

    /**
     * Create string representation of the prop-filter
     *
     * @return string
     */
    public function __toString()
    {
        $filter = '<c:prop-filter name="' . $this->name . '">';

        if ($this->type == self::IS_NOT_DEFINED) {
            $filter .= '<c:is-not-defined/>';
        } elseif ($this->type) {
            $filter .= '<c:text-match match-type="' . $this->type . '"';

            if ($this->collation) {
                $filter .= ' collation="' . $this->collation . '"';
            }

            if ($this->negate) {
                $filter .= ' negate-condition="yes"';
            }

            $filter .= '>' . htmlspecialchars($this->value, \ENT_XML1, 'UTF-8') . '</c:text-match>';
        }

        $filter .= '</c:prop-filter>';

        return $filter;
    }
}
