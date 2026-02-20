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
 * Utilities to handle/migrate Kolab (v3 and v4) files
 */
class Files extends Fs
{
    /**
     * Get file properties/content
     *
     * @param \rcube_imap_generic $imap IMAP client (account)
     * @param Item                $item File item
     */
    public static function fetchKolab3File($imap, Item $item): void
    {
        // Handle file content in memory (up to 20MB), bigger files will use a temp file
        if (!isset($item->data['size']) || $item->data['size'] > Engine::MAX_ITEM_SIZE) {
            // Save the message content to a file
            $location = $item->folder->tempFileLocation($item->id . '.eml');

            $fp = fopen($location, 'w');

            if (!$fp) {
                throw new \Exception("Failed to open 'php://temp' stream");
            }
        }

        $mailbox = $item->data['mailbox'];

        $result = $imap->handlePartBody($mailbox, $item->id, true, 3, $item->data['encoding'], null, $fp ?? null);

        if ($result === false) {
            if (!empty($fp)) {
                fclose($fp);
            }

            throw new \Exception("Failed to fetch IMAP message attachment for {$mailbox}/{$item->id}");
        }

        if (!empty($fp) && !empty($location)) {
            $item->filename = $location;
            fclose($fp);
        } else {
            $item->content = $result;
        }
    }

    /**
     * Get files from Kolab3 (IMAP) folder
     *
     * @param \rcube_imap_generic $imap     IMAP client (account)
     * @param string              $mailbox  Folder name
     * @param array               $existing Files existing at the destination account
     */
    public static function getKolab3Files($imap, $mailbox, $existing = []): array
    {
        // Find file objects
        $search = 'NOT DELETED HEADER X-Kolab-Type "application/x-vnd.kolab.file"';
        $search = $imap->search($mailbox, $search, true);
        if ($search->is_empty()) {
            return [];
        }

        // Get messages' basic headers, include headers for the XML attachment
        // TODO: Limit data in FETCH, we need only INTERNALDATE and BODY.PEEK[3.MIME].
        $uids = $search->get_compressed();
        $messages = $imap->fetchHeaders($mailbox, $uids, true, false, [], ['BODY.PEEK[3.MIME]']);
        $files = [];

        foreach ($messages as $message) {
            [$type, $name, $size, $encoding] = self::parseHeaders($message->bodypart['3.MIME'] ?? '');

            // Sanity check
            if ($name === null || $type === null) {
                continue;
            }

            // Note: We do not really need to fetch and parse Kolab XML

            $mtime = \rcube_utils::anytodatetime($message->internaldate, new \DateTimeZone('UTC'));
            $exists = $existing[$name] ?? null;

            if ($exists && $exists->updated_at == $mtime) {
                // No changes to the file, skip it
                continue;
            }

            $files[] = [
                'id' => $message->uid,
                'existing' => $exists,
                'data' => [
                    'name' => $name,
                    'mailbox' => $mailbox,
                    'encoding' => $encoding,
                    'type' => $type,
                    'size' => $size,
                    'mtime' => $mtime,
                ],
            ];
        }

        return $files;
    }

    /**
     * Get list of Kolab4 files
     *
     * @param Account $account Destination account
     * @param Folder  $folder  Folder
     */
    public static function getKolab4Files(Account $account, Folder $folder): array
    {
        // We assume destination is the local server. Maybe we should be using Cockpit API?
        $collection = self::getFsCollection($account, $folder, false);

        if (!$collection) {
            return [];
        }

        return $collection->children()
            ->select('fs_items.*')
            ->addSelect(DB::raw("(select value from fs_properties where fs_properties.item_id = fs_items.id"
                . " and fs_properties.key = 'name') as name"))
            ->where('type', '&', FsItem::TYPE_FILE)
            ->whereNot('type', '&', FsItem::TYPE_INCOMPLETE)
            ->get()
            ->keyBy('name')
            ->all();
    }

    /**
     * Save a file into Kolab4 storage
     *
     * @param Account $account Destination account
     * @param Item    $item    File item
     */
    public static function saveKolab4File(Account $account, Item $item): void
    {
        // We assume destination is the local server. Maybe we should be using Cockpit API?
        $collection = self::getFsCollection($account, $item->folder, false);

        if (!$collection) {
            throw new \Exception("Failed to find destination collection for {$item->folder->fullname}");
        }

        $params = ['mimetype' => $item->data['type']];

        DB::beginTransaction();

        if ($item->existing) {
            /** @var FsItem $file */
            $file = $item->existing;
        } else {
            $file = new FsItem();
            $file->user_id = $account->getUser()->id;
            $file->type = FsItem::TYPE_FILE;
            $file->save();

            $file->properties()->create(['key' => 'name', 'value' => $item->data['name']]);
            $collection->relations()->create(['related_id' => $file->id]);
        }

        if ($item->filename) {
            $fp = fopen($item->filename, 'r');
        } else {
            $fp = fopen('php://memory', 'r+');
            fwrite($fp, $item->content);
            rewind($fp);
        }

        // TODO: Use proper size chunks

        Storage::fileInput($fp, $params, $file);

        // Update the mtime, must be after fileInput() call
        if (!empty($item->data['mtime'])) {
            $file->updated_at = $item->data['mtime'];
            $file->timestamps = false;
            $file->save();
        }

        DB::commit();

        fclose($fp);
    }

    /**
     * Parse mail attachment headers (get content type, file name and size)
     */
    protected static function parseHeaders(string $input): array
    {
        // Parse headers
        $headers = \rcube_mime::parse_headers($input);

        $ctype = $headers['content-type'] ?? '';
        $disposition = $headers['content-disposition'] ?? '';
        $tokens = preg_split('/;[\s\r\n\t]*/', $ctype . ';' . $disposition);
        $params = [];

        // Extract file parameters
        foreach ($tokens as $token) {
            // TODO: Use order defined by the parameter name not order of occurrence in the header
            if (preg_match('/^(name|filename)\*([0-9]*)\*?="*([^"]+)"*/i', $token, $matches)) {
                $key = strtolower($matches[1]);
                $params["{$key}*"] = ($params["{$key}*"] ?? '') . $matches[3];
            } elseif (str_starts_with($token, 'size=')) {
                $params['size'] = (int) trim(substr($token, 5));
            } elseif (str_starts_with($token, 'filename=')) {
                $params['filename'] = trim(substr($token, 9), '" ');
            }
        }

        $type = explode(';', $ctype)[0] ?? null;
        $size = $params['size'] ?? null;
        $encoding = $headers['content-transfer-encoding'] ?? null;
        $name = $params['filename'] ?? null;

        // Decode file name according to RFC 2231, Section 4
        if (isset($params['filename*']) && preg_match("/^([^']*)'[^']*'(.*)$/", $params['filename*'], $m)) {
            // Note: We ignore charset as it should be always UTF-8
            $name = rawurldecode($m[2]);
        }

        return [$type, $name, $size, $encoding];
    }
}
