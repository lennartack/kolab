<?php

namespace App\Backends\DAV;

class Folder
{
    public const SHARE_ACCESS_NONE = 'not-shared';
    public const SHARE_ACCESS_SHARED = 'shared-owner';
    public const SHARE_ACCESS_READ = 'read';
    public const SHARE_ACCESS_READ_WRITE = 'read-write';

    /** @var ?string Folder location (href property) */
    public $href;

    /** @var ?string Folder name (displayname property) */
    public $name;

    /** @var ?string Folder CTag (getctag property) */
    public $ctag;

    /** @var array Supported component set (supported-*-component-set property) */
    public $components = [];

    /** @var array Supported resource types (resourcetype property) */
    public $types = [];

    /** @var ?string Access rights on a shared folder (share-access property) */
    public $shareAccess;

    /** @var ?string Folder color (calendar-color property) */
    public $color;

    /** @var array Folder 'invites' property */
    public $invites = [];

    /** @var ?string Folder owner (email) */
    public $owner;

    /**
     * Create Folder object from a DOMElement element
     *
     * @param \DOMElement $element DOM element with folder properties
     *
     * @return Folder
     */
    public static function fromDomElement(\DOMElement $element)
    {
        $folder = new self();

        if ($href = $element->getElementsByTagName('href')->item(0)) {
            $folder->href = $href->nodeValue;
        }

        if ($color = $element->getElementsByTagName('calendar-color')->item(0)) {
            if (preg_match('/^#[0-9a-fA-F]{6,8}$/', $color->nodeValue)) {
                $folder->color = substr($color->nodeValue, 1);
            }
        }

        if ($name = $element->getElementsByTagName('displayname')->item(0)) {
            $folder->name = $name->nodeValue;
        }

        if ($ctag = $element->getElementsByTagName('getctag')->item(0)) {
            $folder->ctag = $ctag->nodeValue;
        }

        $components = [];
        if ($set_element = $element->getElementsByTagName('supported-calendar-component-set')->item(0)) {
            foreach ($set_element->getElementsByTagName('comp') as $comp) {
                $components[] = $comp->attributes->getNamedItem('name')->nodeValue;
            }
        }

        $types = [];
        if ($type_element = $element->getElementsByTagName('resourcetype')->item(0)) {
            foreach ($type_element->childNodes as $node) {
                if ($node->nodeType == \XML_ELEMENT_NODE) {
                    $_type = explode(':', $node->nodeName);
                    $types[] = count($_type) > 1 ? $_type[1] : $_type[0];
                }
            }
        }

        $folder->types = $types;
        $folder->components = $components;

        if ($owner = $element->getElementsByTagName('owner')->item(0)) {
            if ($owner->firstChild) {
                $href = $owner->firstChild->nodeValue; // owner principal href
                $href = explode('/', trim($href, '/'));

                $folder->owner = urldecode(end($href));
            }
        }

        // 'share-access' from draft-pot-webdav-resource-sharing
        if ($share = $element->getElementsByTagName('share-access')->item(0)) {
            if ($share->firstChild) {
                $folder->shareAccess = $share->firstChild->localName;
            }
        }

        // 'invite' from draft-pot-webdav-resource-sharing
        if ($invite_element = $element->getElementsByTagName('invite')->item(0)) {
            $invites = [];
            foreach ($invite_element->childNodes as $sharee) {
                /** @var \DOMElement $sharee */
                $href = $sharee->getElementsByTagName('href')->item(0)->nodeValue;
                $status = 'noresponse';

                if ($comment = $sharee->getElementsByTagName('comment')->item(0)) {
                    $comment = $comment->nodeValue;
                }

                if ($displayname = $sharee->getElementsByTagName('displayname')->item(0)) {
                    $displayname = $displayname->nodeValue;
                }

                if ($access = $sharee->getElementsByTagName('share-access')->item(0)) {
                    $access = $access->firstChild->localName;
                } else {
                    $access = self::SHARE_ACCESS_NONE;
                }

                $props = [
                    'invite-noresponse',
                    'invite-accepted',
                    'invite-declined',
                    'invite-invalid',
                    'invite-deleted',
                ];

                foreach ($props as $name) {
                    if ($node = $sharee->getElementsByTagName($name)->item(0)) {
                        $status = str_replace('invite-', '', $node->localName);
                    }
                }

                $invites[$href] = [
                    'access' => $access,
                    'status' => $status,
                    'comment' => $comment,
                    'displayname' => $displayname,
                ];
            }

            $folder->invites = $invites;
        }

        return $folder;
    }

    /**
     * Parse folder properties input into XML string to use in a request
     *
     * @return string
     */
    public function toXML($tag)
    {
        $ns = 'xmlns:d="DAV:"';
        $props = '';
        $type = null;

        if (in_array('addressbook', $this->types)) {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:carddav"';
            $type = 'c:addressbook';
        } elseif (in_array('calendar', $this->types)) {
            $ns .= ' xmlns:c="urn:ietf:params:xml:ns:caldav"';
            $type = 'c:calendar';
        } elseif (in_array('notebook', $this->types)) {
            $ns .= ' xmlns:k="Kolab:"';
            $type = 'k:notebook';
        }

        // Cyrus DAV does not allow resourcetype property change
        if ($tag != 'propertyupdate') {
            $props .= '<d:resourcetype><d:collection/>' . ($type ? "<{$type}/>" : '') . '</d:resourcetype>';
        }

        if (!empty($this->components)) {
            // Note: Normally Cyrus DAV does not allow supported-calendar-component-set property update,
            // but I found in Cyrus code that the action can be forced with force=yes attribute.
            $props .= '<c:supported-calendar-component-set force="yes">';
            foreach ($this->components as $component) {
                $props .= '<c:comp name="' . $component . '"/>';
            }
            $props .= '</c:supported-calendar-component-set>';
        }

        if ($this->name !== null) {
            $props .= '<d:displayname>' . htmlspecialchars($this->name, \ENT_XML1, 'UTF-8') . '</d:displayname>';
        }

        if ($this->color !== null) {
            $color = $this->color;
            if (strlen($color) && $color[0] != '#') {
                $color = '#' . $color;
            }

            $ns .= ' xmlns:a="http://apple.com/ns/ical/"';
            $props .= '<a:calendar-color>' . htmlspecialchars($color, \ENT_XML1, 'UTF-8') . '</a:calendar-color>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . "<d:{$tag} {$ns}><d:set><d:prop>{$props}</d:prop></d:set></d:{$tag}>";
    }

    /**
     * Get XML string for PROPFIND query on a folder
     *
     * @return string
     */
    public static function propfindXML()
    {
        $ns = implode(' ', [
            'xmlns:d="DAV:"',
            'xmlns:cs="http://calendarserver.org/ns/"',
            'xmlns:c="urn:ietf:params:xml:ns:caldav"',
            'xmlns:a="http://apple.com/ns/ical/"',
            // 'xmlns:k="Kolab:"'
        ]);

        // Note: <allprop> does not include some of the properties we're interested in
        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind ' . $ns . '>'
                . '<d:prop>'
                    . '<a:calendar-color/>'
                    . '<c:supported-calendar-component-set/>'
                    . '<cs:getctag/>'
                    // . '<d:acl/>'
                    // . '<d:current-user-privilege-set/>'
                    . '<d:resourcetype/>'
                    . '<d:displayname/>'
                    . '<d:share-access/>' // draft-pot-webdav-resource-sharing-04
                    . '<d:owner/>'
                    . '<d:invite/>'
                    // . '<k:alarms/>'
                . '</d:prop>'
            . '</d:propfind>';
    }
}
