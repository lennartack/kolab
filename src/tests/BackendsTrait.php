<?php

namespace Tests;

use App\Backends\DAV;
use App\Backends\IMAP;
use App\DataMigrator\Account;
use App\DataMigrator\Engine;
use App\Utils;

trait BackendsTrait
{
    protected $clients = [];
    protected $davTypes = [
        Engine::TYPE_EVENT => DAV::TYPE_VEVENT,
        Engine::TYPE_TASK => DAV::TYPE_VTODO,
        Engine::TYPE_CONTACT => DAV::TYPE_VCARD,
        Engine::TYPE_GROUP => DAV::TYPE_VCARD,
        Engine::TYPE_NOTE => DAV::TYPE_NOTE,
    ];

    /**
     * Append an DAV object to a DAV folder
     */
    protected function davAppend(Account $account, $foldername, $filenames, $type, $replace = []): void
    {
        $dav = $this->getDavClient($account);

        $folder = $this->davFindFolder($account, $foldername, $type);

        if (empty($folder)) {
            throw new \Exception("Failed to find folder {$account}/{$foldername}");
        }

        foreach ((array) $filenames as $filename) {
            $path = __DIR__ . '/data/' . $filename;

            if (!file_exists($path)) {
                throw new \Exception("File does not exist: {$path}");
            }

            $content = file_get_contents($path);

            foreach ($replace as $from => $to) {
                $content = preg_replace($from, $to, $content);
            }

            $uid = preg_match('/\nUID:(?:urn:uuid:)?(\S+)/', $content, $m) ? $m[1] : null;

            if (empty($uid)) {
                throw new \Exception("Filed to find UID in {$path}");
            }

            $location = rtrim($folder->href, '/') . '/' . $uid . '.' . pathinfo($filename, \PATHINFO_EXTENSION);

            $content = new DAV\Opaque($content);
            $content->uid = $uid;
            $content->href = $location;
            $content->contentType = $type == Engine::TYPE_CONTACT
                ? 'text/vcard; charset=utf-8'
                : 'text/calendar; charset=utf-8';

            if ($dav->create($content) === false) {
                throw new \Exception("Failed to append object into {$account}/{$location}");
            }
        }
    }

    /**
     * Delete a DAV folder
     */
    protected function davCreateFolder(Account $account, $foldername, $type): void
    {
        if ($this->davFindFolder($account, $foldername, $type)) {
            return;
        }

        $dav = $this->getDavClient($account);

        $dav_type = $this->davTypes[$type];
        $home = $dav->getHome($dav_type);
        $folder_id = Utils::uuidStr();
        $collection_type = DAV::collectionType($dav_type);

        // We create all folders on the top-level
        $folder = new DAV\Folder();
        $folder->name = $foldername;
        $folder->href = rtrim($home, '/') . '/' . $folder_id;
        $folder->components = [$dav_type];
        $folder->types = ['collection', $collection_type];

        if ($type == Engine::TYPE_NOTE) {
            $folder->components = [];
            $folder->name = null;
            $folder->href = rtrim($home, '/') . '/' . $foldername;
        }

        if ($dav->folderCreate($folder) === false) {
            throw new \Exception("Failed to create folder {$account}/{$folder->href}");
        }
    }

    /**
     * Create a DAV folder
     */
    protected function davDeleteFolder(Account $account, $foldername, $type): void
    {
        $folder = $this->davFindFolder($account, $foldername, $type);

        if (empty($folder)) {
            return;
        }

        $dav = $this->getDavClient($account);

        if ($dav->folderDelete($folder->href) === false) {
            throw new \Exception("Failed to delete folder {$account}/{$foldername}");
        }
    }

    /**
     * Remove all objects from a DAV folder
     */
    protected function davEmptyFolder(Account $account, $foldername, $type): void
    {
        $dav = $this->getDavClient($account);

        foreach ($this->davList($account, $foldername, $type) as $object) {
            if ($dav->delete($object->href) === false) {
                throw new \Exception("Failed to delete {$account}/{object->href}");
            }
        }
    }

    /**
     * Find a DAV folder
     */
    protected function davFindFolder(Account $account, $foldername, $type)
    {
        $dav = $this->getDavClient($account);

        $list = $dav->listFolders($this->davTypes[$type]);

        if ($list === false) {
            throw new \Exception("Failed to list '{$type}' folders on {$account}");
        }

        foreach ($list as $folder) {
            if (str_replace(' » ', '/', $folder->name) === $foldername) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * List objects in a DAV folder
     */
    protected function davList(Account $account, $foldername, $type): array
    {
        $folder = $this->davFindFolder($account, $foldername, $type);

        if (empty($folder)) {
            throw new \Exception("Failed to find folder {$account}/{$foldername}");
        }

        $dav = $this->getDavClient($account);

        if ($type == Engine::TYPE_NOTE) {
            $result = $dav->listNotes($folder->href);
        } else {
            $search = new DAV\Search($this->davTypes[$type], true);
            $result = $dav->search($folder->href, $search);
        }

        if ($result === false) {
            throw new \Exception("Failed to get items from a DAV folder {$account}/{$folder->href}");
        }

        return $result;
    }

    /**
     * List DAV folders
     */
    protected function davListFolders(Account $account, $type): array
    {
        $dav = $this->getDavClient($account);

        $list = $dav->listFolders($this->davTypes[$type]);

        if ($list === false) {
            throw new \Exception("Failed to list '{$type}' folders on {$account}");
        }

        $result = [];

        foreach ($list as $folder) {
            // skip shared folders (iRony)
            if (str_starts_with($folder->name, 'shared » ') || $folder->name[0] == '(') {
                continue;
            }

            $result[$folder->href] = str_replace(' » ', '/', $folder->name);
        }

        return $result;
    }

    /**
     * Get configured/initialized DAV client
     */
    protected function getDavClient(Account $account): DAV
    {
        $clientId = (string) $account;

        if (empty($this->clients[$clientId])) {
            $uri = preg_replace('/^dav/', 'http', $account->uri);
            $this->clients[$clientId] = DAV::getInstance($account->username, $account->password, $uri);
        }

        return $this->clients[$clientId];
    }

    /**
     * Get configured/initialized IMAP client
     */
    protected function getImapClient(Account $account): \rcube_imap_generic
    {
        $clientId = (string) $account;

        if (empty($this->clients[$clientId])) {
            $class = new \ReflectionClass(IMAP::class);

            $initIMAP = $class->getMethod('initIMAP');
            $getConfig = $class->getMethod('getConfig');
            $initIMAP->setAccessible(true);
            $getConfig->setAccessible(true);

            $config = [
                'user' => $account->username,
                'password' => $account->password,
            ];

            $login_as = $account->params['user'] ?? null;
            $config = array_merge($getConfig->invoke(null), $config);

            $this->clients[$clientId] = $initIMAP->invokeArgs(null, [$config, $login_as]);
        }

        return $this->clients[$clientId];
    }

    /**
     * Initialize an account
     */
    protected function initAccount(Account $account): void
    {
        // Remove all objects from all (personal) folders
        if ($account->scheme == 'dav' || $account->scheme == 'davs') {
            foreach (['event', 'task', 'contact'] as $type) {
                foreach ($this->davListFolders($account, $type) as $folder) {
                    $this->davEmptyFolder($account, $folder, $type);
                }
            }
        } else {
            // TODO: Delete all folders except the default ones?
            foreach ($this->imapListFolders($account) as $folder) {
                $this->imapEmptyFolder($account, $folder);
            }
        }
    }

    /**
     * Append an email message to the IMAP folder
     */
    protected function imapAppend(Account $account, $folder, $filename, $flags = [], $date = null, $replace = [])
    {
        $imap = $this->getImapClient($account);

        $source = __DIR__ . '/data/' . $filename;

        if (!file_exists($source)) {
            throw new \Exception("File does not exist: {$source}");
        }

        $source = file_get_contents($source);
        $source = preg_replace('/\r?\n/', "\r\n", $source);

        foreach ($replace as $from => $to) {
            $source = preg_replace($from, $to, $source);
        }

        $uid = $imap->append($folder, $source, $flags, $date, true);

        if ($uid === false) {
            throw new \Exception("Failed to append mail into {$account}/{$folder}");
        }

        return $uid;
    }

    /**
     * Create an IMAP folder
     */
    protected function imapCreateFolder(Account $account, $folder, bool $subscribe = false): void
    {
        $imap = $this->getImapClient($account);

        if (!$imap->createFolder($folder)) {
            if (str_contains($imap->error, "Mailbox already exists")) {
                // Not an error
            } else {
                throw new \Exception("Failed to create an IMAP folder {$account}/{$folder}");
            }
        }

        if ($subscribe) {
            if (!$imap->subscribe($folder)) {
                throw new \Exception("Failed to subscribe an IMAP folder {$account}/{$folder}");
            }
        }
    }

    /**
     * Delete an IMAP folder
     */
    protected function imapDeleteFolder(Account $account, $folder): void
    {
        $imap = $this->getImapClient($account);

        if (!$imap->deleteFolder($folder)) {
            if (str_contains($imap->error, "Mailbox does not exist")) {
                // Ignore
            } else {
                throw new \Exception("Failed to delete an IMAP folder {$account}/{$folder}");
            }
        }

        $imap->unsubscribe($folder);
    }

    /**
     * Remove all objects from a folder
     */
    protected function imapEmptyFolder(Account $account, $folder): void
    {
        $imap = $this->getImapClient($account);

        $deleted = $imap->flag($folder, '1:*', 'DELETED');

        if (!$deleted) {
            throw new \Exception("Failed to empty an IMAP folder {$account}/{$folder}");
        }

        // send expunge command in order to have the deleted message really deleted from the folder
        $imap->expunge($folder, '1:*');
    }

    /**
     * List emails over IMAP
     */
    protected function imapList(Account $account, $folder): array
    {
        $imap = $this->getImapClient($account);

        $messages = $imap->fetchHeaders($folder, '1:*', true, false, ['Message-Id']);

        if ($messages === false) {
            throw new \Exception("Failed to get all IMAP message headers for {$account}/{$folder}");
        }

        return $messages;
    }

    /**
     * List IMAP folders
     */
    protected function imapListFolders(Account $account, bool $subscribed = false): array
    {
        $imap = $this->getImapClient($account);

        $folders = $subscribed ? $imap->listSubscribed('', '') : $imap->listMailboxes('', '');

        if ($folders === false) {
            throw new \Exception("Failed to list IMAP folders for {$account}");
        }

        $folders = array_filter(
            $folders,
            static function ($folder) {
                return !preg_match('~(Shared Folders|Other Users)/.*~', $folder);
            }
        );

        return $folders;
    }

    /**
     * Mark an email message as read over IMAP
     */
    protected function imapFlagAs(Account $account, $folder, $uids, $flags): void
    {
        $imap = $this->getImapClient($account);

        foreach ($flags as $flag) {
            if (str_starts_with($flag, 'UN')) {
                $flagged = $imap->unflag($folder, $uids, substr($flag, 2));
            } else {
                $flagged = $imap->flag($folder, $uids, $flag);
            }

            if (!$flagged) {
                throw new \Exception("Failed to flag an IMAP messages as SEEN in {$account}/{$folder}");
            }
        }
    }
}
