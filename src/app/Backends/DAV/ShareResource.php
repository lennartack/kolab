<?php

namespace App\Backends\DAV;

class ShareResource implements \Stringable
{
    public const ACCESS_NONE = 'no-access';
    public const ACCESS_READ = 'read';
    public const ACCESS_READ_WRITE = 'read-write';

    /** @var string Object content type (of the string representation) */
    public $contentType = 'application/davsharing+xml; charset=utf-8';

    /** @var ?string Resource (folder) location */
    public $href;

    /** @var ?array Resource sharees list */
    public $sharees;

    /**
     * Create Share Resource object from a DOMElement element
     *
     * @param \DOMElement $element DOM element with notification properties
     *
     * @return Notification
     */
    public static function fromDomElement(\DOMElement $element)
    {
        throw new \Exception("Not implemented");
    }

    /**
     * Convert a share-resource into an XML string to use in a request
     */
    public function __toString(): string
    {
        // TODO: Sharee should be an object not array

        $props = '';
        foreach ($this->sharees as $href => $sharee) {
            if (!is_array($sharee)) {
                $sharee = ['access' => $sharee];
            }

            $props .= '<d:sharee>'
                . '<d:href>' . htmlspecialchars($href, \ENT_XML1, 'UTF-8') . '</d:href>'
                . '<d:share-access><d:' . ($sharee['access'] ?? self::ACCESS_NONE) . '/></d:share-access>';

            if (isset($sharee['comment']) && strlen($sharee['comment'])) {
                $props .= '<d:comment>' . htmlspecialchars($sharee['comment'], \ENT_XML1, 'UTF-8') . '</d:comment>';
            }

            if (isset($sharee['displayname']) && strlen($sharee['displayname'])) {
                $props .= '<d:prop><d:displayname>'
                    . htmlspecialchars($sharee['comment'], \ENT_XML1, 'UTF-8')
                    . '</d:displayname></d:prop>';
            }

            $props .= '</d:sharee>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:share-resource xmlns:d="DAV:">' . $props . '</d:share-resource>';
    }
}
