<?php

namespace App\Backends\DAV;

class Note extends CommonObject
{
    /** @var array Note categories (tags) */
    public $categories = [];

    /** @var ?string Note title */
    public $displayname;

    /** @var ?\DateTime Note last modification date-time */
    public $lastModified;

    /** @var array Note links */
    public $links = [];

    /** @var ?string File type */
    public $mimetype;

    /**
     * Create Note object from a DOMElement element
     *
     * @param \DOMElement $element DOM element with notification properties
     *
     * @return Note
     */
    public static function fromDomElement(\DOMElement $element)
    {
        $note = new self();

        if ($href = $element->getElementsByTagName('href')->item(0)) {
            $note->href = $href->nodeValue;
            $note->uid = preg_replace('/\.[a-z]+$/', '', pathinfo($note->href, \PATHINFO_FILENAME));
        }

        $note->mimetype = strtolower((string) $element->getElementsByTagName('getcontenttype')->item(0)?->nodeValue);
        $note->displayname = $element->getElementsByTagName('displayname')->item(0)?->nodeValue;

        if ($dt = $element->getElementsByTagName('getlastmodified')->item(0)?->nodeValue) {
            $note->lastModified = new \DateTime($dt);
        }

        foreach (['links', 'categories'] as $name) {
            if ($list = $element->getElementsByTagName($name)->item(0)) {
                $tag = $name == 'categories' ? 'category' : 'link';
                foreach ($list->getElementsByTagName($tag) as $item) {
                    $note->{$name}[] = $item->nodeValue;
                }
            }
        }

        return $note;
    }

    /**
     * Get XML string for PROPPATCH request
     */
    public function toXML(): string
    {
        $props = '<d:displayname>' . htmlspecialchars($this->displayname, \ENT_XML1, 'UTF-8') . '</d:displayname>';

        foreach (['categories', 'links'] as $name) {
            $list = '';
            foreach ($this->{$name} as $item) {
                $tag = $name == 'categories' ? 'category' : 'link';
                $list .= "<k:{$tag}>" . htmlspecialchars($item, \ENT_XML1, 'UTF-8') . "</k:{$tag}>";
            }

            $props .= "<k:{$name}>{$list}</k:{$name}>";
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propertyupdate xmlns:d="DAV:" xmlns:k="Kolab:">'
                . '<d:set>'
                    . '<d:prop>' . $props . '</d:prop>'
                . '</d:set>'
            . '</d:propertyupdate>';
    }

    /**
     * Get XML string for PROPFIND query on a notification
     *
     * @return string
     */
    public static function propfindXML()
    {
        // Note: With <d:allprop/> notificationtype is not returned, but it's essential
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:k="Kolab:">'
                . '<d:prop>'
                    . '<d:displayname />'
                    . '<d:getcontenttype />'
                    . '<d:getlastmodified />'
                    . '<k:links />'
                    . '<k:categories />'
                . '</d:prop>'
            . '</d:propfind>';
    }
}
