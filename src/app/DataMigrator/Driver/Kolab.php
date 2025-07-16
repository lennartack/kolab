<?php

namespace App\DataMigrator\Driver;

use App\Auth\Utils;
use App\DataMigrator\Account;
use App\DataMigrator\Engine;
use App\DataMigrator\Interface\Folder;
use App\DataMigrator\Interface\ImporterInterface;
use App\DataMigrator\Interface\Item;
use App\User;

/**
 * Data migration from/to a Kolab server
 */
class Kolab extends IMAP
{
    protected const CTYPE_KEY = '/shared/vendor/kolab/folder-type';
    protected const CTYPE_KEY_PRIVATE = '/private/vendor/kolab/folder-type';
    protected const UID_KEY = '/shared/vendor/kolab/uniqueid';
    protected const UID_KEY_CYRUS = '/shared/vendor/cmu/cyrus-imapd/uniqueid';
    protected const COLOR_KEY = '/shared/vendor/kolab/color';
    protected const COLOR_KEY_PRIVATE = '/private/vendor/kolab/color';

    protected const DAV_TYPES = [
        Engine::TYPE_CONTACT,
        Engine::TYPE_EVENT,
        Engine::TYPE_TASK,
    ];
    protected const IMAP_TYPES = [
        Engine::TYPE_MAIL,
        Engine::TYPE_CONFIGURATION,
        Engine::TYPE_FILE,
    ];

    /** @var DAV DAV importer/exporter engine */
    protected $davDriver;

    /**
     * Object constructor
     */
    public function __construct(Account $account, Engine $engine)
    {
        // TODO: Right now we require IMAP server and DAV host names, but for Kolab we should be able
        // to detect IMAP and DAV locations, e.g. so we can just provide "kolabnow.com" as an input
        // Note: E.g. KolabNow uses different hostname for DAV, pass it as a query parameter 'dav_host'.

        // Setup IMAP connection
        $uri = (string) $account;
        $uri = preg_replace('/^kolab:/', 'tls:', $uri);

        parent::__construct(new Account($uri), $engine);

        // Support user impersonation in Kolab v4 DAV by issuing a token
        // Note: This is not needed for Kolab v3 (iRony), but the kolab_auth_proxy plugin must be enabled there.
        if ($account->loginas && $account->scheme != 'kolab3' && ($user = $account->getUser())) {
            // Cyrus DAV does not support proxy authorization via DAV. Even though it has
            // the Authorize-As header, it is used only for cummunication with Murder backends.
            // We use a one-time token instead. It's valid for 6 hours, assume it's enough time
            // to migrate an account.
            $account->password = Utils::tokenCreate((string) $user->id, 6 * 60 * 60);
            $account->username = $account->loginas;
            $account->loginas = null;
        }

        // Setup DAV connection
        $uri = sprintf(
            '%s://%s:%s@%s%s',
            $account->params['dav_protocol'] ?? "davs",
            urlencode($account->username),
            urlencode($account->password),
            $account->params['dav_host'] ?? $account->host,
            $account->params['dav_path'] ?? "",
        );

        $this->davDriver = new DAV(new Account($uri), $engine);
    }

    /**
     * Authenticate
     */
    public function authenticate(): void
    {
        // IMAP
        parent::authenticate();

        // DAV
        $this->davDriver->authenticate();
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
        if ($this->account->scheme == 'kolab3') {
            throw new \Exception("Kolab v3 destination not supported");
        }

        // IMAP
        if ($folder->type == Engine::TYPE_MAIL) {
            parent::createFolder($folder);
            return;
        }

        // DAV
        if (in_array($folder->type, self::DAV_TYPES)) {
            $this->davDriver->createFolder($folder);
            return;
        }

        // Files
        if ($folder->type == Engine::TYPE_FILE) {
            Kolab\Files::createFolder($this->account, $folder);
            return;
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
        // Destination server is always Kolab4, we don't support migration into Kolab3
        if ($this->account->scheme == 'kolab3') {
            throw new \Exception("Kolab v3 destination not supported");
        }

        // IMAP
        if ($item->folder->type == Engine::TYPE_MAIL) {
            parent::createItem($item);
            return;
        }

        // DAV
        if (in_array($item->folder->type, self::DAV_TYPES)) {
            $this->davDriver->createItem($item);
            return;
        }

        // Files
        if ($item->folder->type == Engine::TYPE_FILE) {
            Kolab\Files::saveKolab4File($this->account, $item);
            return;
        }

        // Configuration (v3 tags)
        if ($item->folder->type == Engine::TYPE_CONFIGURATION) {
            $this->initIMAP();
            Kolab\Tags::migrateKolab3Tag($this->imap, $item);
            return;
        }
    }

    /**
     * Fetching a folder metadata
     */
    public function fetchFolder(Folder $folder): void
    {
        // No support for migration from Kolab4 yet
        if ($this->account->scheme != 'kolab3') {
            throw new \Exception("Kolab v4 source not supported");
        }

        // IMAP (and DAV)
        // TODO: We can treat 'file' folders the same, but we have no sharing in Kolab4 yet for them
        if ($folder->type == Engine::TYPE_MAIL || in_array($folder->type, self::DAV_TYPES)) {
            parent::fetchFolder($folder);
            return;
        }
    }

    /**
     * Fetching an item
     */
    public function fetchItem(Item $item): void
    {
        // No support for migration from Kolab4 yet
        if ($this->account->scheme != 'kolab3') {
            throw new \Exception("Kolab v4 source not supported");
        }

        // IMAP
        if ($item->folder->type == Engine::TYPE_MAIL) {
            parent::fetchItem($item);
            return;
        }

        // DAV
        if (in_array($item->folder->type, self::DAV_TYPES)) {
            $this->davDriver->fetchItem($item);
            return;
        }

        // Files (IMAP)
        if ($item->folder->type == Engine::TYPE_FILE) {
            $this->initIMAP();
            Kolab\Files::fetchKolab3File($this->imap, $item);
            return;
        }

        // Configuration (v3 tags)
        if ($item->folder->type == Engine::TYPE_CONFIGURATION) {
            $this->initIMAP();
            Kolab\Tags::fetchKolab3Tag($this->imap, $item);
            return;
        }
    }

    /**
     * Fetch a list of folder items from the source server
     */
    public function fetchItemList(Folder $folder, $callback, ImporterInterface $importer): void
    {
        // Note: No support for migration from Kolab4 yet
        if ($this->account->scheme != 'kolab3') {
            throw new \Exception("Kolab v4 source not supported");
        }

        // IMAP
        if ($folder->type == Engine::TYPE_MAIL) {
            parent::fetchItemList($folder, $callback, $importer);
            return;
        }

        // DAV
        if (in_array($folder->type, self::DAV_TYPES)) {
            $this->davDriver->fetchItemList($folder, $callback, $importer);
            return;
        }

        // Files
        if ($folder->type == Engine::TYPE_FILE) {
            $this->initIMAP();

            // Get existing files from the destination account
            $existing = $importer->getItems($folder);

            $mailbox = self::toUTF7($folder->fullname);
            foreach (Kolab\Files::getKolab3Files($this->imap, $mailbox, $existing) as $file) {
                $file['folder'] = $folder;
                $item = Item::fromArray($file);
                $callback($item);
            }
        }

        // Configuration (v3 tags)
        if ($folder->type == Engine::TYPE_CONFIGURATION) {
            $this->initIMAP();

            // Get existing tags from the destination account
            $existing = $importer->getItems($folder);

            $mailbox = self::toUTF7($folder->fullname);
            foreach (Kolab\Tags::getKolab3Tags($this->imap, $mailbox, $existing) as $tag) {
                $tag['folder'] = $folder;
                $item = Item::fromArray($tag);
                $callback($item);
            }
        }
    }

    /**
     * Get folders hierarchy
     */
    public function getFolders($types = []): array
    {
        $this->initIMAP();

        // Note: No support for migration from Kolab4 yet.
        // We use IMAP to get the list of all folders, but we'll get folder contents from IMAP and DAV.
        // This will not work with Kolab4
        if ($this->account->scheme != 'kolab3') {
            throw new \Exception("Kolab v4 source not supported");
        }

        $result = [];

        // Get IMAP (mail and configuration) folders
        $folders = $this->imap->listMailboxes('', '', ['SUBSCRIBED']);

        if ($folders === false) {
            throw new \Exception("Failed to get list of IMAP folders");
        }

        $meta_keys = [
            self::CTYPE_KEY,
            self::CTYPE_KEY_PRIVATE,
            self::COLOR_KEY,
            self::COLOR_KEY_PRIVATE,
            self::UID_KEY,
            self::UID_KEY_CYRUS,
        ];

        $metadata = $this->imap->getMetadata('*', $meta_keys);

        if ($metadata === null) {
            throw new \Exception("Failed to get METADATA for IMAP folders. Not a Kolab server?");
        }

        $configuration_folders = [];

        foreach ($folders as $folder) {
            $folder_meta = $metadata[$folder] ?? [];

            $type = 'mail';
            if (!empty($folder_meta[self::CTYPE_KEY_PRIVATE])) {
                $type = $folder_meta[self::CTYPE_KEY_PRIVATE];
            } elseif (!empty($folder_meta[self::CTYPE_KEY])) {
                $type = $folder_meta[self::CTYPE_KEY];
            }

            [$type] = explode('.', $type);

            // These types we do not support
            if (!in_array($type, self::IMAP_TYPES) && !in_array($type, self::DAV_TYPES)) {
                continue;
            }

            if (!empty($types) && !in_array($type, $types)) {
                continue;
            }

            if ($this->shouldSkip($folder)) {
                \Log::debug("Skipping folder {$folder}.");
                continue;
            }

            $is_subscribed = !empty($this->imap->data['LIST'])
                && !empty($this->imap->data['LIST'][$folder])
                && in_array('\Subscribed', $this->imap->data['LIST'][$folder]);

            $folder = Folder::fromArray([
                'fullname' => self::fromUTF7($folder),
                'type' => $type,
                'subscribed' => $is_subscribed || $folder === 'INBOX',
            ]);

            $uid = $folder_meta[self::UID_KEY] ?? $folder_meta[self::UID_KEY_CYRUS] ?? null;

            if (in_array($type, self::DAV_TYPES)) {
                if (empty($uid)) {
                    throw new \Exception("Missing UID for folder {$folder->fullname}");
                }

                // In tests we're using Cyrus to emulate iRony, but it uses different folder paths/names
                if (!empty($this->account->params['v4dav'])) {
                    // Note: Use this code path (option) for tests
                    $folder->id = $this->davDriver->getFolderPath($folder);
                } else {
                    $path = $type == 'contact' ? 'addressbooks' : 'calendars';
                    $folder->id = sprintf('/%s/%s/%s', $path, $this->account->email, $uid);
                }

                if (!empty($folder_meta[self::COLOR_KEY_PRIVATE])) {
                    $folder->color = $folder_meta[self::COLOR_KEY_PRIVATE];
                } elseif (!empty($folder_meta[self::COLOR_KEY])) {
                    $folder->color = $folder_meta[self::COLOR_KEY];
                }
            }

            if ($type == Engine::TYPE_CONFIGURATION) {
                $configuration_folders[] = $folder;
            } else {
                $result[] = $folder;
            }
        }

        // Put configuration folders at the end of the list
        // Migrating tags requires all members already migrated
        if (!empty($configuration_folders)) {
            $result = array_merge($result, $configuration_folders);
        }

        return $result;
    }

    /**
     * Get a list of folder items from the destination server, limited to their essential propeties
     * used in incremental migration to skip unchanged items.
     */
    public function getItems(Folder $folder): array
    {
        // Destination server is always Kolab4, we don't support migration into Kolab3
        if ($this->account->scheme == 'kolab3') {
            throw new \Exception("Kolab v3 destination not supported");
        }

        // IMAP
        if ($folder->type == Engine::TYPE_MAIL) {
            return parent::getItems($folder);
        }

        // DAV
        if (in_array($folder->type, self::DAV_TYPES)) {
            return $this->davDriver->getItems($folder);
        }

        // Files
        if ($folder->type == Engine::TYPE_FILE) {
            return Kolab\Files::getKolab4Files($this->account, $folder);
        }

        // Configuration folder (tags)
        if ($folder->type == Engine::TYPE_CONFIGURATION) {
            $this->initIMAP();
            return Kolab\Tags::getKolab4Tags($this->imap);
        }

        return [];
    }

    /**
     * Initialize IMAP connection and authenticate the user
     */
    protected function initIMAP(): void
    {
        parent::initIMAP();

        // Advertise itself as a Kolab client, in case Guam is in the way
        $this->imap->id(['name' => 'Cockpit/Kolab']);
    }
}
