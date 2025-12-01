<?php

namespace App\Backends\DAV;

class InviteReply implements \Stringable
{
    public const INVITE_ACCEPTED = 'accepted';
    public const INVITE_DECLINED = 'declined';

    /** @var string Object content type (of the string representation) */
    public $contentType = 'application/davsharing+xml; charset=utf-8';

    /** @var ?string Invite reply type */
    public $type;

    /** @var ?string Invite reply comment */
    public $comment;

    /**
     * Create Notification object from a DOMElement element
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
     * Convert an invite reply into an XML string to use in a request
     */
    public function __toString(): string
    {
        $reply = '<d:invite-' . ($this->type ?: self::INVITE_ACCEPTED) . '/>';

        // Note: <create-in> and <slug> are ignored by Cyrus

        if (!empty($this->comment)) {
            $reply .= '<d:comment>' . htmlspecialchars($this->comment, \ENT_XML1, 'UTF-8') . '</d:comment>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:invite-reply xmlns:d="DAV:">' . $reply . '</d:invite-reply>';
    }
}
