<?php

namespace App\DataMigrator\Driver;

use App\DataMigrator\Account;
use App\DataMigrator\Engine;
use App\DataMigrator\Interface\ExporterInterface;
use App\DataMigrator\Interface\Folder;
use App\DataMigrator\Interface\ImporterInterface;
use App\DataMigrator\Interface\Item;
use App\DataMigrator\Interface\ItemSet;
use App\User;

/**
 * Data migration from IMAP
 */
class IMAP implements ExporterInterface, ImporterInterface
{
    /** @const int Max number of items to migrate in one go */
    protected const CHUNK_SIZE = 100;

    /** @var ?\rcube_imap_generic Imap backend */
    protected $imap;

    /** @var array IMAP configuration */
    protected $config;

    /** @var Account Account to operate on */
    protected $account;

    /** @var Engine Data migrator engine */
    protected $engine;

    /**
     * Object constructor
     */
    public function __construct(Account $account, Engine $engine)
    {
        $this->account = $account;
        $this->engine = $engine;
        $this->config = self::getConfig($account);
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        if (!$this->imap) {
            return;
        }

        try {
            $this->imap->closeConnection();
        } catch (\Throwable $e) {
            // Ignore. It may throw when destructing the object in tests
            // We also don't really care abount an error on this operation
        }
    }

    /**
     * Authenticate
     */
    public function authenticate(): void
    {
        $this->initIMAP();
    }

    /**
     * Create a folder.
     *
     * @param Folder $folder Folder data
     *
     * @throws \Exception on error
     */
    public function createFolder(Folder $folder): void
    {
        $this->initIMAP();

        if ($folder->type != 'mail') {
            throw new \Exception("IMAP does not support folder of type {$folder->type}");
        }

        $mailbox = self::toUTF7($folder->targetname);

        if ($mailbox != 'INBOX' && !$this->imap->createFolder($mailbox)) {
            if (str_contains($this->imap->error, "Mailbox already exists")) {
                // Not an error
                \Log::debug("Folder already exists: {$mailbox}");
            } else {
                \Log::warning("Failed to create the folder: {$this->imap->error}");
                throw new \Exception("Failed to create an IMAP folder {$mailbox}");
            }
        }

        if ($folder->subscribed) {
            if (!$this->imap->subscribe($mailbox)) {
                \Log::warning("Failed to subscribe to the folder: {$this->imap->error}");
            }
        }

        // ACL
        if (!empty($folder->acl)) {
            // Note: We assume the destination account is controlled by this cockpit instance
            $emails = array_diff(array_keys($folder->acl), [$this->account->email]);
            foreach (User::whereIn('email', $emails)->pluck('email') as $email) {
                if (!$this->imap->setACL($mailbox, $email, $folder->acl[$email])) {
                    \Log::warning("Failed to set ACL for {$email} on the folder: {$this->imap->error}");
                }
            }
            /*
            // FIXME: Looks like this might not work on Cyrus IMAP depending on config
            if (!empty($folder->acl['anyone'])) {
                if (!$this->imap->setACL($mailbox, 'anyone', $folder->acl['anyone'])) {
                    \Log::warning("Failed to set ACL for anyone on the folder: {$this->imap->error}");
                }
            }
            */
        }

        // Metadata
        if (!empty($folder->metadata)) {
            \Log::info("Setting METADATA " . var_export($folder->metadata, true));
            if (!$this->imap->setMetadata($mailbox, $folder->metadata)) {
                \Log::warning("Failed to set METADATA for {$mailbox} on the folder: {$this->imap->error}");
            }
        }
    }

    /**
     * Create an item in a folder.
     *
     * @param Item $item Item to import
     *
     * @throws \Exception
     */
    public function createItem(Item $item): void
    {
        $this->initIMAP();

        $mailbox = self::toUTF7($item->folder->targetname);
        $result = null;

        if (strlen($item->content)) {
            $result = $this->imap->append(
                $mailbox,
                $item->content,
                $item->data['flags'] ?? [],
                $item->data['internaldate'] ?? null,
                true
            );
        } elseif ($item->filename) {
            $result = $this->imap->appendFromFile(
                $mailbox,
                $item->filename,
                null,
                $item->data['flags'] ?? [],
                $item->data['internaldate'] ?? null,
                true
            );
        }

        if ($result === false) {
            $msg = "Failed to append IMAP message ({$item->id}) into {$mailbox}: {$this->imap->error}";

            // Don't abort the migration because of malformed messages
            if ($this->imap->error == "APPEND: Message contains invalid header") {
                \Log::warning($msg);
            } else {
                throw new \Exception($msg);
            }
        }

        // When updating an existing email message we have to...
        if ($item->existing) {
            if (!empty($result)) {
                // Remove the old one
                $this->imap->flag($mailbox, $item->existing['uid'], 'DELETED');
                $this->imap->expunge($mailbox, $item->existing['uid']);
            } else {
                // Update flags
                // TODO: Here we may produce more STORE commands than necessary, we could improve this
                // if we make flag()/unflag() methods to accept multiple flags
                foreach ($item->existing['flags'] as $flag) {
                    if (!in_array($flag, $item->data['flags'])) {
                        $this->imap->unflag($mailbox, $item->existing['uid'], $flag);
                    }
                }
                foreach ($item->data['flags'] as $flag) {
                    if (!in_array($flag, $item->existing['flags'])) {
                        $this->imap->flag($mailbox, $item->existing['uid'], $flag);
                    }
                }
            }
        }
    }

    /**
     * Fetching a folder metadata
     */
    public function fetchFolder(Folder $folder): void
    {
        $this->initIMAP();

        $mailbox = self::toUTF7($folder->targetname);

        // Get folder ACL
        if ($this->imap->getCapability('ACL')) {
            // TODO: Support RIGHTS= capability
            $acl = $this->imap->getACL($mailbox);
            if (is_array($acl)) {
                $acl = array_change_key_case($acl, \CASE_LOWER);
                // owner should always have full rights, no need to migrate this entry,
                // and it would be problematic if the source email is different than the destination email
                unset($acl[$this->account->email]);
                $folder->acl = $acl;
            } else {
                \Log::warning("Failed to get ACL for the folder: {$this->imap->error}");
            }
        }

        if ($this->imap->getCapability('METADATA') && !empty($this->account->metadata)) {
            $folder->metadata = $this->imap->getMetadata($mailbox, $this->account->metadata)[$mailbox] ?? null;
        }
    }

    /**
     * Fetching an item
     */
    public function fetchItem(Item $item): void
    {
        $this->initIMAP();

        [$uid, $messageId] = explode(':', $item->id, 2);

        $mailbox = self::toUTF7($item->folder->fullname);

        // Get message flags
        $header = $this->imap->fetchHeader($mailbox, (int) $uid, true, false, ['FLAGS']);

        if ($header === false) {
            throw new \Exception("Failed to get IMAP message headers for {$mailbox}/{$uid}");
        }

        // Remove flags that we can't append (e.g. RECENT)
        $flags = $this->filterImapFlags(array_keys($header->flags));

        // If message already exists in the destination account we should update only flags
        // and be done with it. On the other hand for Drafts it's not unusual to get completely
        // different body for the same Message-ID. Same can happen not only in Drafts, I suppose.
        // So, we compare size and INTERNALDATE timestamp.
        if (
            !$item->existing
            || $header->size != $item->existing['size']
            || \rcube_utils::strtotime($header->internaldate) != \rcube_utils::strtotime($item->existing['date'])
        ) {
            // Handle message content in memory (up to 20MB), bigger messages will use a temp file
            if ($header->size > Engine::MAX_ITEM_SIZE) {
                // Save the message content to a file
                $location = $item->folder->tempFileLocation($uid . '.eml');

                $fp = fopen($location, 'w');

                if (!$fp) {
                    throw new \Exception("Failed to open 'php://temp' stream");
                }

                $result = $this->imap->handlePartBody($mailbox, $uid, true, '', null, null, $fp);
            } else {
                $result = $this->imap->handlePartBody($mailbox, $uid, true);
            }

            if ($result === false) {
                if (!empty($fp)) {
                    fclose($fp);
                }

                throw new \Exception("Failed to fetch IMAP message for {$mailbox}/{$uid}");
            }

            if (!empty($fp) && !empty($location)) {
                $item->filename = $location;
                fclose($fp);
            } else {
                $item->content = $result;
            }
        }

        $item->data = [
            'flags' => $flags,
            'internaldate' => $header->internaldate,
        ];
    }

    /**
     * Fetch a list of folder items
     */
    public function fetchItemList(Folder $folder, $callback, ImporterInterface $importer): void
    {
        $this->initIMAP();

        // Get existing messages' headers from the destination mailbox
        $existing = $importer->getItems($folder);

        $mailbox = self::toUTF7($folder->fullname);

        // TODO: We should probably first use SEARCH/SORT to skip messages marked as \Deleted
        // It would also allow us to get headers in chunks 200 messages at a time, or so.
        // TODO: fetchHeaders() fetches too many headers, we should slim-down, here we need
        // only UID FLAGS INTERNALDATE BODY.PEEK[HEADER.FIELDS (DATE FROM MESSAGE-ID)]
        $messages = $this->imap->fetchHeaders($mailbox, '1:*', true, false, ['Message-Id']);

        if ($messages === false) {
            throw new \Exception("Failed to get all IMAP message headers for {$mailbox}");
        }

        if (empty($messages)) {
            \Log::debug("Nothing to migrate for {$mailbox}");
            return;
        }

        $set = new ItemSet();

        foreach ($messages as $message) {
            // If Message-Id header does not exist create it based on internaldate/From/Date
            $id = $this->getMessageId($message, $mailbox);

            // Skip message that exists and did not change
            $exists = $existing[$id] ?? null;
            if ($exists) {
                if (
                    $message->size == $exists['size']
                    && \rcube_utils::strtotime($message->internaldate) == \rcube_utils::strtotime($exists['date'])
                    && $this->filterImapFlags(array_keys($message->flags)) == $exists['flags']
                ) {
                    continue;
                }
            }

            $set->items[] = Item::fromArray([
                'id' => $message->uid . ':' . $id,
                'folder' => $folder,
                'existing' => $exists,
            ]);

            if (count($set->items) == self::CHUNK_SIZE) {
                $callback($set);
                $set = new ItemSet();
            }
        }

        if (count($set->items)) {
            $callback($set);
        }

        // TODO: Delete messages that do not exist anymore?
    }

    /**
     * Get folders hierarchy
     */
    public function getFolders($types = []): array
    {
        $this->initIMAP();

        $folders = $this->imap->listMailboxes('', '');

        if ($folders === false) {
            throw new \Exception("Failed to get list of IMAP folders");
        }

        $subscribed = $this->imap->listSubscribed('', '');

        if ($subscribed === false) {
            throw new \Exception("Failed to get list of subscribed IMAP folders");
        }

        $result = [];

        foreach ($folders as $folder) {
            if ($this->shouldSkip($folder)) {
                \Log::debug("Skipping folder {$folder}.");
                continue;
            }

            $result[] = Folder::fromArray([
                'fullname' => self::fromUTF7($folder),
                'type' => 'mail',
                'subscribed' => in_array($folder, $subscribed) || $folder === 'INBOX',
            ]);
        }

        return $result;
    }

    /**
     * Get a list of folder items, limited to their essential propeties
     * used in incremental migration to skip unchanged items.
     */
    public function getItems(Folder $folder): array
    {
        $this->initIMAP();

        $mailbox = self::toUTF7($folder->targetname ?: $folder->fullname);

        // TODO: We should probably first use SEARCH/SORT to skip messages marked as \Deleted
        // TODO: fetchHeaders() fetches too many headers, we should slim-down, here we need
        // only UID FLAGS INTERNALDATE BODY.PEEK[HEADER.FIELDS (DATE FROM MESSAGE-ID)]
        $messages = $this->imap->fetchHeaders($mailbox, '1:*', true, false, ['Message-Id']);

        if ($messages === false) {
            throw new \Exception("Failed to get IMAP message headers in {$mailbox}");
        }

        $result = [];

        foreach ($messages as $message) {
            // Remove flags that we can't append (e.g. RECENT)
            $flags = $this->filterImapFlags(array_keys($message->flags));

            // Generate message ID if the header does not exist
            $id = $this->getMessageId($message, $mailbox);

            $result[$id] = [
                'uid' => $message->uid,
                'flags' => $flags,
                'size' => $message->size,
                'date' => $message->internaldate,
            ];
        }

        return $result;
    }

    /**
     * Initialize IMAP connection and authenticate the user
     */
    protected function initIMAP(): void
    {
        if ($this->imap) {
            return;
        }

        $this->imap = new \rcube_imap_generic();

        if (\config('app.debug')) {
            $this->imap->setDebug(true, 'App\Support\Facades\IMAP::logDebug');
        }

        $this->imap->connect(
            $this->config['host'],
            $this->config['user'],
            $this->config['password'],
            $this->config['options']
        );

        if (!$this->imap->connected()) {
            $message = sprintf(
                "Login failed for %s against %s. %s",
                $this->config['user'],
                $this->config['host'],
                $this->imap->error
            );

            \Log::error($message);

            throw new \Exception("Connection to IMAP failed");
        }
    }

    /**
     * Get IMAP configuration
     */
    protected static function getConfig(Account $account): array
    {
        $uri = \parse_url($account->uri);
        $default_port = 143;
        $ssl_mode = null;

        if (isset($uri['scheme'])) {
            if (preg_match('/^(ssl|imaps)/', $uri['scheme'])) {
                $default_port = 993;
                $ssl_mode = 'ssl';
            } elseif ($uri['scheme'] === 'tls') {
                $ssl_mode = 'tls';
            }
        }

        $config = [
            'host' => $uri['host'],
            'user' => $account->username,
            'password' => $account->password,
            'options' => [
                'port' => !empty($uri['port']) ? $uri['port'] : $default_port,
                'ssl_mode' => $ssl_mode,
                'socket_options' => [
                    'ssl' => [
                        // TODO: These configuration options make sense for "local" Kolab IMAP,
                        // but when connecting to external one we might want to just disable
                        // cert validation, or make it optional via Account URI parameters
                        'verify_peer' => \config('services.imap.verify_peer'),
                        'verify_peer_name' => \config('services.imap.verify_peer'),
                        'verify_host' => \config('services.imap.verify_host'),
                    ],
                ],
            ],
        ];

        // User impersonation. Example URI: imap://admin:password@hostname:143?user=user%40domain.tld
        if ($account->loginas) {
            $config['options']['auth_cid'] = $config['user'];
            $config['options']['auth_pw'] = $config['password'];
            $config['options']['auth_type'] = 'PLAIN';
            $config['user'] = $account->loginas;
        }

        return $config;
    }

    /**
     * Limit IMAP flags to these that can be migrated
     */
    protected function filterImapFlags($flags)
    {
        // TODO: Support custom flags migration

        return array_filter(
            $flags,
            function ($flag) {
                return isset($this->imap->flags[$flag]);
            }
        );
    }

    /**
     * Check if the folder should not be migrated
     */
    protected function shouldSkip($folder): bool
    {
        // TODO: This should probably use NAMESPACE information

        if (preg_match('~(Shared Folders|Other Users)/.*~', $folder)) {
            return true;
        }

        return false;
    }

    /**
     * Return Message-Id, generate unique identifier if Message-Id does not exist
     */
    protected function getMessageId($message, $folder): string
    {
        if (!empty($message->messageID)) {
            return $message->messageID;
        }

        return md5($folder . $message->from . $message->timestamp);
    }

    /**
     * Convert UTF8 string to UTF7-IMAP encoding
     */
    protected static function toUTF7(string $string): string
    {
        return \mb_convert_encoding($string, 'UTF7-IMAP', 'UTF8');
    }

    /**
     * Convert UTF7-IMAP string to UTF8 encoding
     */
    protected static function fromUTF7(string $string): string
    {
        return \mb_convert_encoding($string, 'UTF8', 'UTF7-IMAP');
    }
}
