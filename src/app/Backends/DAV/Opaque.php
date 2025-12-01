<?php

namespace App\Backends\DAV;

class Opaque extends CommonObject implements \Stringable
{
    protected $content;

    public function __construct($content = null, $is_file = false)
    {
        if ($is_file) {
            $this->content = file_get_contents($content);
        } else {
            $this->content = $content;
        }
    }

    /**
     * Create string representation of the DAV object
     *
     * @return string
     */
    public function __toString()
    {
        return $this->content;
    }

    /**
     * Create an object from a DOMElement element
     *
     * @param \DOMElement $element DOM element with object properties
     *
     * @return Opaque
     */
    public static function fromDomElement(\DOMElement $element)
    {
        /** @var self $object */
        $object = parent::fromDomElement($element);

        foreach (['address-data', 'calendar-data'] as $name) {
            if ($data = $element->getElementsByTagName($name)->item(0)) {
                $object->setContent($data->nodeValue);
                break;
            }
        }

        return $object;
    }

    /**
     * Set the object content
     */
    public function setContent($content): void
    {
        $this->content = $content;
    }
}
