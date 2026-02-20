<?php

namespace App\DataMigrator\Driver\Kolab;

use App\DataMigrator\Driver\Kolab as Driver;
use App\DataMigrator\Interface\Item;

/**
 * Utilities to handle/migrate Kolab (v3 and v4) tags
 */
class Tags
{
    private const ANNOTATE_KEY_PREFIX = '/vendor/kolab/tag/v1/';
    private const ANNOTATE_VALUE = '1';
    private const METADATA_ROOT = 'INBOX';
    private const METADATA_TAGS_KEY = '/private/vendor/kolab/tags/v1';

    /**
     * Get all tag properties, resolve tag members
     *
     * @param \rcube_imap_generic $imap IMAP client (account)
     * @param Item                $item Tag item
     */
    public static function fetchKolab3Tag($imap, Item $item): void
    {
        // Note: We already have all tag properties, we need to resolve
        // member URLs into folder+message-id pairs so we can later annotate
        // mail messages

        $user = $imap->getUser();
        $members = [];

        foreach (($item->data['member'] ?? []) as $member) {
            // TODO: For now we'll ignore tasks/notes, but in the future we
            // should migrate task tags into task CATEGORIES property (dunno notes).
            if (!str_starts_with($member, 'imap://')) {
                continue;
            }

            // Sample member: imap:///(user/username@domain|shared)/<folder>/<UID>?<search_params>
            // parse_url does not work with imap:/// prefix
            $url = parse_url(substr($member, 8));
            $path = explode('/', $url['path']);
            parse_str($url['query'], $params);

            // Skip members without Message-ID
            if (empty($params['message-id'])) {
                continue;
            }

            $uid = array_pop($path);
            $ns = array_shift($path);

            // TODO: For now we ignore shared folders
            if ($ns != 'user') {
                continue;
            }

            $path = array_map('rawurldecode', $path);
            $username = array_shift($path);
            $folder = implode('/', $path);

            if ($username == $user) {
                if (!strlen($folder)) {
                    $folder = 'INBOX';
                }
            }

            if (!isset($members[$folder])) {
                $members[$folder] = [];
            }

            // Get the folder and message-id, we can't use UIDs, they are different in the target account
            $members[$folder][] = $params['message-id'];
        }

        $item->data['member'] = $members;
    }

    /**
     * Find the configuration folder in IMAP
     *
     * @param \rcube_imap_generic $imap IMAP client (account)
     */
    protected static function findConfigurationFolder($imap): ?string
    {
        $meta_keys = [
            Driver::CTYPE_KEY,
            Driver::CTYPE_KEY_PRIVATE,
        ];

        $metadata = $imap->getMetadata('*', $meta_keys);

        if ($metadata === null) {
            throw new \Exception("Failed to get METADATA for IMAP folders. Not a Kolab server?");
        }

        foreach ($metadata as $folder => $meta) {
            $type = 'mail';
            if (!empty($meta[Driver::CTYPE_KEY_PRIVATE])) {
                $type = $meta[Driver::CTYPE_KEY_PRIVATE];
            } elseif (!empty($meta[Driver::CTYPE_KEY])) {
                $type = $meta[Driver::CTYPE_KEY];
            }

            if (str_starts_with($type, 'configuration') && !preg_match('~(Shared Folders|Other Users)/.*~', $folder)) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * Get Kolab3 relations (generic and tags)
     *
     * @param \rcube_imap_generic $imap IMAP client (account)
     */
    public static function getKolab3Relations($imap): array
    {
        // Cache this information as it can be invoked multiple times
        if (isset($imap->data['RELATIONS'])) {
            return $imap->data['RELATIONS'];
        }

        $folder = self::findConfigurationFolder($imap);

        if ($folder === null) {
            return $imap->data['RELATIONS'] = [];
        }

        return $imap->data['RELATIONS'] = self::getKolab3Tags($imap, $folder, [], null);
    }

    /**
     * Get tags from Kolab3 folder
     *
     * @param \rcube_imap_generic $imap     IMAP client (account)
     * @param string              $mailbox  Configuration folder name
     * @param array               $existing Tags existing at the destination account
     * @param ?string             $type     Relation type filter
     */
    public static function getKolab3Tags($imap, $mailbox, $existing = [], $type = 'tag'): array
    {
        // Find relation objects
        $search = 'NOT DELETED HEADER X-Kolab-Type "application/x-vnd.kolab.configuration.relation"';
        $search = $imap->search($mailbox, $search, true);
        if ($search->is_empty()) {
            return [];
        }

        // Get messages' basic headers, include headers for the XML attachment
        $uids = $search->get_compressed();
        $messages = $imap->fetchHeaders($mailbox, $uids, true, false, [], ['BODY.PEEK[2.MIME]']);
        $tags = [];

        foreach ($messages as $message) {
            $headers = \rcube_mime::parse_headers($message->bodypart['2.MIME'] ?? '');

            // Sanity check, part 2 is expected to be Kolab XML attachment
            if (stripos($headers['content-type'] ?? '', 'application/vnd.kolab+xml') === false) {
                continue;
            }

            // Get the XML content
            $encoding = $headers['content-transfer-encoding'] ?? '8bit';
            $xml = $imap->handlePartBody($mailbox, $message->uid, true, 2, $encoding);

            // Remove namespace so xpath queries below are short
            $xml = str_replace(' xmlns="http://kolab.org"', '', $xml);
            $xml = simplexml_load_string($xml);

            if ($xml === false) {
                throw new \Exception("Failed to parse XML for {$mailbox}/{$message->uid}");
            }

            $tag = ['member' => []];
            foreach ($xml->xpath('/configuration/*') as $node) {
                $nodeName = $node->getName();
                if (in_array($nodeName, ['relationType', 'name', 'color', 'last-modification-date', 'member'])) {
                    if ($nodeName == 'member') {
                        $tag['member'][] = (string) $node;
                    } else {
                        $tag[$nodeName] = (string) $node;
                    }
                }
            }

            if (empty($type) || $tag['relationType'] === $type) {
                if (empty($tag['last-modification-date'])) {
                    $tag['last-modification-date'] = $message->internaldate;
                }

                $exists = null;
                foreach ($existing as $existing_tag) {
                    if ($existing_tag['name'] == $tag['name']) {
                        if (isset($existing_tag['mtime']) && $existing_tag['mtime'] == $tag['last-modification-date']) {
                            // No changes to the tag, skip it
                            continue 2;
                        }

                        $exists = $existing_tag;
                        break;
                    }
                }

                $tags[] = [
                    'id' => $message->uid,
                    'class' => 'tag',
                    'existing' => $exists,
                    'data' => $tag,
                ];
            }
        }

        return $tags;
    }

    /**
     * Get list of Kolab4 tags
     *
     * @param \rcube_imap_generic $imap IMAP client (account)
     */
    public static function getKolab4Tags($imap): array
    {
        $tags = [];

        if ($meta = $imap->getMetadata(self::METADATA_ROOT, self::METADATA_TAGS_KEY)) {
            $tags = json_decode($meta[self::METADATA_ROOT][self::METADATA_TAGS_KEY], true);
        }

        return $tags;
    }

    /**
     * Get list of Kolab4 tag members (message UIDs)
     *
     * @param \rcube_imap_generic $imap     IMAP client (account)
     * @param string              $tag_name Tag name
     * @param string              $folder   IMAP folder name
     */
    public static function getKolab4TagMembers($imap, string $tag_name, string $folder): array
    {
        $criteria = sprintf(
            'ANNOTATION %s value.priv %s',
            $imap->escape(self::ANNOTATE_KEY_PREFIX . $tag_name),
            $imap->escape(self::ANNOTATE_VALUE, true)
        );

        $search = $imap->search($folder, $criteria, true);

        if ($search->is_error()) {
            throw new \Exception("Failed to SEARCH in {$folder}. Error: {$imap->error}");
        }

        return $search->get();
    }

    /**
     * Migrate Kolab3 tag into Kolab4 tag (including tag associations)
     *
     * @param \rcube_imap_generic $imap IMAP client (target account)
     * @param Item                $item Tag item
     */
    public static function migrateKolab3Tag($imap, Item $item): void
    {
        $tags = self::getKolab4Tags($imap);

        $tag_name = $item->data['name'];
        $found = false;

        // Find the tag
        foreach ($tags as &$tag) {
            if ($tag['name'] == $tag_name) {
                $tag['color'] = $item->data['color'] ?? null;
                $tag['mtime'] = $item->data['last-modification-date'] ?? null;
                $tag = array_filter($tag);
                $found = true;
                break;
            }
        }

        if (!$found) {
            $tags[] = array_filter([
                'name' => $tag_name,
                'color' => $item->data['color'] ?? null,
                'mtime' => $item->data['last-modification-date'] ?? null,
            ]);
        }

        self::saveKolab4Tags($imap, $tags);

        // Migrate members
        // For each folder, search messages by Message-ID and annotate them
        // TODO: In incremental migration (if tag already exists) we probably should
        // remove the annotation from all messages in all folders first.
        foreach (($item->data['member'] ?? []) as $folder => $ids) {
            $uids = [];
            $search = '';
            $prefix = '';
            // Do search in chunks
            foreach ($ids as $idx => $id) {
                $str = ' HEADER MESSAGE-ID ' . $imap->escape($id);
                $len = strlen($search) + strlen($str) + strlen($prefix) + 100;
                $is_last = $idx == count($ids) - 1;

                if ($len > 65536 || $is_last) {
                    if ($is_last) {
                        $prefix .= strlen($search) ? ' OR' : '';
                        $search .= $str;
                    }

                    $search = $imap->search($folder, $prefix . $search, true);
                    if ($search->is_error()) {
                        throw new \Exception("Failed to SEARCH in {$folder}. Error: {$imap->error}");
                    }

                    $uids = array_merge($uids, $search->get());
                    $search = '';
                    $prefix = '';
                }

                $prefix .= strlen($search) ? ' OR' : '';
                $search .= $str;
            }

            if (!empty($uids)) {
                $annotation = [];
                $annotation[self::ANNOTATE_KEY_PREFIX . $tag_name] = ['value.priv' => self::ANNOTATE_VALUE];

                $uids = $imap->compressMessageSet(array_unique($uids));
                $res = $imap->storeMessageAnnotation($folder, $uids, $annotation);

                if (!$res) {
                    throw new \Exception("Failed to ANNOTATE in {$folder}. Error: {$imap->error}");
                }
            }
        }
    }

    /**
     * Get list of Kolab4 tags
     *
     * @param \rcube_imap_generic $imap IMAP client (account)
     * @param array               $tags List of tags
     */
    public static function saveKolab4Tags($imap, array $tags): void
    {
        $metadata = json_encode($tags, \JSON_INVALID_UTF8_IGNORE | \JSON_UNESCAPED_UNICODE);

        if (!$imap->setMetadata(self::METADATA_ROOT, [self::METADATA_TAGS_KEY => $metadata])) {
            throw new \Exception("Failed to store tags in IMAP. Error: {$imap->error}");
        }
    }
}
