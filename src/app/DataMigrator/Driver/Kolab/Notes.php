<?php

namespace App\DataMigrator\Driver\Kolab;

use App\DataMigrator\Account;
use App\DataMigrator\Engine;
use App\DataMigrator\Interface\Folder;
use App\DataMigrator\Interface\Item;
use App\Fs\Item as FsItem;
use App\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

/**
 * Utilities to handle/migrate Kolab (v3 and v4) notes
 */
class Notes extends Fs
{
    /**
     * Create a Kolab4 notes collection (folder)
     *
     * @param Account $account Destination account
     * @param Folder  $folder  Folder object
     */
    public static function createFolder(Account $account, Folder $folder): void
    {
        // We assume destination is the local server. Maybe we should be using Cockpit API?
        self::getFsCollection($account, $folder, true, true);
    }

    /**
     * Get note properties/content
     *
     * @param \rcube_imap_generic $imap IMAP client (account)
     * @param Item                $item File item
     */
    public static function fetchKolab3Note($imap, Item $item): void
    {
        $mailbox = $item->data['mailbox'];

        $result = $imap->handlePartBody($mailbox, $item->data['uid'], true, 2, $item->data['encoding'], null, null);

        if ($result === false) {
            throw new \Exception("Failed to fetch IMAP message attachment for {$mailbox}/{$item->data['uid']}");
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadXML($result, \LIBXML_PARSEHUGE);

        $summary = (string) $doc->getElementsByTagName('summary')->item(0)?->textContent;
        $html = (string) $doc->getElementsByTagName('description')->item(0)?->textContent;

        // In Kolab v3 notes can be also plain text, convert to HTML
        if (!str_contains($html, '<')) {
            $html = '<html><pre>' . $html . '</pre></html>';
        }

        $item->data['mimetype'] = 'text/html';
        $item->data['displayname'] = $summary;

        // Handle file content in memory (up to 20MB), bigger notes will use a temp file
        if (strlen($html) > Engine::MAX_ITEM_SIZE) {
            // Save the message content to a file
            $location = $item->folder->tempFileLocation($item->id . '.html');

            if (file_put_contents($location, $html) === false) {
                throw new \Exception("Failed to write to a temp file at {$location}");
            }

            $item->filename = $location;
        } else {
            $item->content = $html;
        }
    }

    /**
     * Get notes from Kolab3 (IMAP) folder
     *
     * @param \rcube_imap_generic $imap     IMAP client (account)
     * @param string              $mailbox  Folder name
     * @param array               $existing Files existing at the destination account
     */
    public static function getKolab3Notes($imap, $mailbox, $existing = []): array
    {
        // Find file objects
        $search = 'NOT DELETED HEADER X-Kolab-Type "application/x-vnd.kolab.note"';
        $search = $imap->search($mailbox, $search, true);
        if ($search->is_empty()) {
            return [];
        }

        $relations = Tags::getKolab3Relations($imap);

        // TODO: Compare existing and migrated note regarding

        // Get messages' basic headers, include headers for the XML attachment
        // TODO: Limit data in FETCH, we need only INTERNALDATE, SIZE and SUBJECT.
        $uids = $search->get_compressed();
        $messages = $imap->fetchHeaders($mailbox, $uids, true, false, [], ['BODY.PEEK[2.MIME]']);
        $notes = [];

        foreach ($messages as $message) {
            // Sanity check
            if (empty($message->subject)) {
                continue;
            }

            $mtime = \rcube_utils::anytodatetime($message->internaldate, new \DateTimeZone('UTC'));
            $links = [];
            $categories = [];

            foreach ($relations as $relation) {
                if (($found = array_search('urn:uuid:' . $message->subject, $relation['data']['member'])) !== false) {
                    if (!empty($relation['data']['name'])) {
                        $categories[] = $relation['data']['name'];
                    } else {
                        $members = $relation['data']['member'];
                        unset($members[$found]);
                        $links = array_merge($members, $links);
                    }
                }
            }

            $links = array_values(array_unique($links));
            $categories = array_values(array_unique($categories));

            $exists = $existing[$message->subject] ?? null;
            if ($exists && $exists->updated_at == $mtime
                && $exists->links == $links
                && $exists->categories == $categories
            ) {
                // No changes to the note, skip it
                continue;
            }

            $headers = \rcube_mime::parse_headers($message->bodypart['2.MIME'] ?? '');

            // Sanity check, part 2 is expected to be Kolab XML attachment
            if (stripos($headers['content-type'] ?? '', 'application/vnd.kolab+xml') === false) {
                continue;
            }

            // Note: We do not need to fetch and parse Kolab XML yet (we'll do it in fetch*())

            $notes[] = [
                'id' => $message->subject,
                'existing' => $exists,
                'data' => [
                    'mailbox' => $mailbox,
                    'size' => $message->size,
                    'mtime' => $mtime,
                    'uid' => $message->uid,
                    'encoding' => $headers['content-transfer-encoding'] ?? '8bit',
                    'links' => $links,
                    'categories' => $categories,
                ],
            ];
        }

        return $notes;
    }

    /**
     * Get list of Kolab4 notes
     *
     * @param Account $account Destination account
     * @param Folder  $folder  Folder
     */
    public static function getKolab4Notes(Account $account, Folder $folder): array
    {
        // We assume destination is the local server. Maybe we should be using Cockpit API?
        $collection = self::getFsCollection($account, $folder, false, true);

        if (!$collection) {
            return [];
        }

        return $collection->children()
            ->select('fs_items.*')
            ->addSelect(DB::raw("(select value from fs_properties where fs_properties.item_id = fs_items.id"
                . " and fs_properties.key = 'name') as name"))
            ->addSelect(DB::raw("(select value from fs_properties where fs_properties.item_id = fs_items.id"
                . " and fs_properties.key = 'dav:links') as links"))
            ->addSelect(DB::raw("(select value from fs_properties where fs_properties.item_id = fs_items.id"
                . " and fs_properties.key = 'dav:categories') as categories"))
            ->where('type', '&', FsItem::TYPE_FILE)
            ->whereNot('type', '&', FsItem::TYPE_INCOMPLETE)
            ->get()
            ->keyBy(static function ($item, int $key) {
                // Get UID from the filename
                // @phpstan-ignore-next-line
                return str_replace('.html', '', $item->name);
            })
            ->each(static function ($item) {
                // @phpstan-ignore-next-line
                $item->links = $item->links ? json_decode($item->links, true) : [];
                // @phpstan-ignore-next-line
                $item->categories = $item->categories ? json_decode($item->categories, true) : [];
            })
            ->all();
    }

    /**
     * Save a file into Kolab4 storage
     *
     * @param Account $account Destination account
     * @param Item    $item    File item
     */
    public static function saveKolab4Note(Account $account, Item $item): void
    {
        // We assume destination is the local server. Maybe we should be using Cockpit API?
        $collection = self::getFsCollection($account, $item->folder, false, true);

        if (!$collection) {
            throw new \Exception("Failed to find destination collection for {$item->folder->fullname}");
        }

        $params = ['mimetype' => $item->data['mimetype']];

        DB::beginTransaction();

        if ($item->existing) {
            /** @var FsItem $file */
            $file = $item->existing;
            $file->setProperties(self::noteProperties($item, true));
        } else {
            $file = new FsItem();
            $file->user_id = $account->getUser()->id;
            $file->type = FsItem::TYPE_FILE;
            $file->save();

            $properties = self::noteProperties($item);
            $properties[] = ['key' => 'name', 'value' => $item->id . '.html'];

            $file->properties()->createMany($properties);
            $collection->relations()->create(['related_id' => $file->id]);
        }

        if ($item->filename) {
            $fp = fopen($item->filename, 'r');
        } else {
            $fp = fopen('php://memory', 'r+');
            fwrite($fp, $item->content);
            rewind($fp);
        }

        Storage::fileInput($fp, $params, $file);

        // Update the mtime, must be after fileInput() call
        if (!empty($item->data['mtime'])) {
            FsItem::where('id', $file->id)->update(['updated_at' => $item->data['mtime']]);
        }

        DB::commit();

        fclose($fp);
    }

    /**
     * Extract Kolab4 note properties from Kolab3 note data
     */
    protected static function noteProperties(Item $item, $key_value = false): array
    {
        $properties = [];

        foreach (['displayname', 'links', 'categories'] as $prop) {
            if (isset($item->data[$prop])) {
                if (is_array($item->data[$prop])) {
                    if (empty($item->data[$prop])) {
                        if (!$key_value) {
                            continue;
                        }
                        $value = null;
                    } else {
                        $value = json_encode(array_values(array_unique($item->data[$prop])));
                    }
                } else {
                    $value = $item->data[$prop];
                }

                if ($key_value) {
                    $properties["dav:{$prop}"] = $value;
                } else {
                    $properties[] = ['key' => "dav:{$prop}", 'value' => $value];
                }
            }
        }

        return $properties;
    }
}
