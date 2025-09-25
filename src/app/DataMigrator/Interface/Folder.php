<?php

namespace App\DataMigrator\Interface;

use App\DataMigrator\Queue;

/**
 * Data object representing a folder
 */
class Folder
{
    /** @var mixed Folder identifier */
    public $id;

    /** @var int Number of items in the folder */
    public $total;

    /** @var string Folder class */
    public $class;

    /** @var ?string A color assigned to the folder */
    public $color;

    /** @var string Folder Kolab object type */
    public $type;

    /** @var string Folder name (UTF-8) */
    public $name;

    /** @var string Folder name with path (UTF-8) */
    public $fullname;

    /** @var string Target folder name with path (UTF-8) */
    public $targetname;

    /** @var string Storage location (for temporary data) */
    public $location;

    /** @var string Migration queue identifier */
    public $queueId;

    /** @var bool Folder subscription state */
    public $subscribed = true;

    /** @var array Access Control list (email => rights) */
    public $acl = [];

    /** @var array Extra (temporary, cache) data */
    public $data = [];

    /** @var ?array IMAP metadata */
    public $metadata;

    /**
     * Create Folder instance from an array
     */
    public static function fromArray(array $data = []): self
    {
        $obj = new self();

        foreach ($data as $key => $value) {
            $obj->{$key} = $value;
        }

        return $obj;
    }

    /**
     * Returns location of a temp file for an Item content
     */
    public function tempFileLocation(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9_:@.-]/', '', $filename);

        $location = $this->location;

        // TODO: What if parent folder not yet exists?
        if (!file_exists($location)) {
            mkdir($location, 0o740, true);
        }

        $location .= '/' . $filename;

        return $location;
    }
}
