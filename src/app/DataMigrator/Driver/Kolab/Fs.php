<?php

namespace App\DataMigrator\Driver\Kolab;

use App\DataMigrator\Account;
use App\DataMigrator\Interface\Folder;
use App\Fs\Item as FsItem;
use Illuminate\Support\Facades\DB;

/**
 * Utility to handle migration to Kolab v4 file storage
 */
class Fs
{
    /**
     * Create a Kolab4 files collection (folder)
     *
     * @param Account $account Destination account
     * @param Folder  $folder  Folder object
     */
    public static function createFolder(Account $account, Folder $folder): void
    {
        // We assume destination is the local server. Maybe we should be using Cockpit API?
        self::getFsCollection($account, $folder, true);
    }

    /**
     * Find (and optionally create) a Kolab4 files collection
     *
     * @param Account $account Destination account
     * @param Folder  $folder  Folder object
     * @param bool    $create  Create collection(s) if it does not exist
     * @param bool    $flat    Flatten the folder hierarchy
     *
     * @return ?FsItem Collection object if found
     */
    protected static function getFsCollection(Account $account, Folder $folder, bool $create = false, bool $flat = false)
    {
        if (!empty($folder->data['collection'])) {
            return $folder->data['collection'];
        }

        // We assume destination is the local server. Maybe we should be using Cockpit API?
        $user = $account->getUser();

        // TODO: For now we assume '/' is the IMAP hierarchy separator. This may not work with dovecot.
        if ($flat) {
            $path = [str_replace('/', ' » ', $folder->fullname)];
        } else {
            $path = explode('/', $folder->fullname);
        }

        $collection = null;

        // Create folder (and the whole tree) if it does not exist yet
        foreach ($path as $name) {
            $result = $user->fsItems()->select('fs_items.*');

            if ($collection) {
                $result->join('fs_relations', 'fs_items.id', '=', 'fs_relations.related_id')
                    ->where('fs_relations.item_id', $collection->id);
            } else {
                $result->leftJoin('fs_relations', 'fs_items.id', '=', 'fs_relations.related_id')
                    ->whereNull('fs_relations.related_id');
            }

            $found = $result->join('fs_properties', 'fs_items.id', '=', 'fs_properties.item_id')
                ->where('type', '&', FsItem::TYPE_COLLECTION)
                ->where('key', 'name')
                ->where('value', $name)
                ->first();

            if (!$found) {
                if ($create) {
                    $coltype = FsItem::TYPE_COLLECTION;
                    if ($folder->type == 'note') {
                        $coltype |= FsItem::TYPE_NOTEBOOK;
                    }

                    DB::beginTransaction();
                    $col = $user->fsItems()->create(['type' => $coltype]);
                    $col->properties()->create(['key' => 'name', 'value' => $name]);
                    if ($collection) {
                        $collection->relations()->create(['related_id' => $col->id]);
                    }
                    $collection = $col;
                    DB::commit();
                } else {
                    return null;
                }
            } else {
                $collection = $found;
            }
        }

        $folder->data['collection'] = $collection;

        return $collection;
    }
}
