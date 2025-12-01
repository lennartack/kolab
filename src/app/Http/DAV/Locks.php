<?php

namespace App\Http\DAV;

use App\Fs\Item;
use App\Fs\Lock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Sabre\DAV\Exception;
use Sabre\DAV\Locks\Backend\AbstractBackend;
use Sabre\DAV\Locks\LockInfo;

/**
 * The Lock manager allows you to handle all file-locks centrally.
 */
class Locks extends AbstractBackend
{
    /**
     * Returns a list of Sabre\DAV\Locks\LockInfo objects.
     *
     * This method should return all the locks for a particular uri, including
     * locks that might be set on a parent uri.
     *
     * If returnChildLocks is set to true, this method should also look for
     * any locks in the subtree of the uri for locks.
     *
     * @param string $uri
     * @param bool   $returnChildLocks
     *
     * @return array<LockInfo> List of locks
     */
    public function getLocks($uri, $returnChildLocks = false)
    {
        \Log::debug('[DAV] GET-LOCKS: ' . $uri);

        // Note: We're disabling exceptions here, otherwise it has unwanted effects
        // in places where Sabre checks locks on non-existing paths
        $ids = Node::resolvePath($uri, true);

        if (empty($ids)) {
            return [];
        }

        // Note: If any node in the path does not exist returned array will contain only
        // existing ones
        $all_count = substr_count($uri, '/') + 1;
        $id = null;
        if ($exists = $all_count == count($ids)) {
            $id = array_pop($ids);
        }

        $locks = Lock::select()
            ->whereRaw('created_at > now() - interval timeout second or timeout = -1')
            ->where(function (Builder $query) use ($id, $ids) {
                if ($id) {
                    $query->where('item_id', $id);
                }

                if (!empty($ids)) {
                    $query->orWhere(function (Builder $query) use ($ids) {
                        $query->whereIn('item_id', $ids)->whereNot('depth', 0);
                    });
                }
            })
            ->get();

        if ($exists && $returnChildLocks) {
            // Include locks for all children of $id, and their children, and so on
            // TODO: It could be skipped if $id node is not a collection
            $add = Lock::select('fs_locks.*')->distinct()
                ->whereRaw('created_at > now() - interval timeout second or timeout = -1')
                ->join(
                    DB::raw(
                        '(with recursive children as ('
                    . "select related_id from fs_relations where item_id = '{$id}'"
                    . ' union all'
                    . ' select r2.related_id from fs_relations r2 inner join children c where r2.item_id = c.related_id'
                    . ')'
                    . ' select related_id as id from children) as items',
                    ),
                    'items.id',
                    '=',
                    'fs_locks.item_id'
                )
                ->get();

            $locks = $locks->merge($add);
        }

        $path = explode('/', $uri);
        $uri_map = $id ? [$id => $uri] : [];
        foreach ($ids as $i => $node_id) {
            $uri_map[$node_id] = implode('/', array_slice($path, 0, $i + 1));
        }

        return $locks->map(function ($lock) use ($uri_map) {
            $lock_uri = $uri_map[$lock->item_id] ?? null;
            if ($lock_uri === null) {
                // This is for the $uri's children
                // Note: This assumes a node has only one parent
                // FIXME: Some optimization of this process would be nice (e.g. done via the big query above)
                $item = Item::find($lock->item_id);
                $lock_uri = '/' . $item->getProperty('name');
                while ($parent = $item->parents()->first()) {
                    if (isset($uri_map[$parent->id])) {
                        $uri_map[$item->id] = $lock_uri = $uri_map[$parent->id] . $lock_uri;
                        break;
                    }
                    $lock_uri = '/' . $parent->getProperty('name') . $lock_uri;
                    $item = $parent;
                }
            }

            $lockInfo = new LockInfo();
            $lockInfo->owner = $lock->owner;
            $lockInfo->token = $lock->token;
            $lockInfo->timeout = $lock->timeout;
            $lockInfo->created = $lock->created_at->getTimestamp();
            $lockInfo->scope = $lock->scope;
            $lockInfo->depth = $lock->depth;
            $lockInfo->uri = $lock_uri;

            return $lockInfo;
        })
            ->all();
    }

    /**
     * Locks a uri.
     *
     * @param string $uri
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function lock($uri, LockInfo $lockInfo)
    {
        \Log::debug('[DAV] LOCK: ' . $uri);

        if (!strlen($uri)) {
            throw new Exception\Forbidden("Cannot lock the root");
        }

        // We're making the lock timeout 30 minutes
        $lockInfo->timeout = 30 * 60;
        $lockInfo->created = time();

        $ids = Node::resolvePath($uri);
        $item_id = array_pop($ids);

        Lock::upsert(
            [[
                'item_id' => $item_id,
                'depth' => $lockInfo->depth,
                'owner' => trim((string) $lockInfo->owner),
                'scope' => $lockInfo->scope,
                'timeout' => $lockInfo->timeout,
                'token' => $lockInfo->token,
                'created_at' => \now(),
            ]],
            ['item_id', 'token'],
            // Note: As far as I can see a lock update is used to refresh a lock,
            // in sach case only creation time property is expected to be updated.
            [/* 'scope', 'depth', 'timeout', */ 'created_at']
        );

        return true;
    }

    /**
     * Removes a lock from a uri.
     *
     * @param string   $uri      Node location
     * @param LockInfo $lockInfo Lock information (e.g. token)
     *
     * @return bool
     */
    public function unlock($uri, LockInfo $lockInfo)
    {
        \Log::debug('[DAV] UNLOCK: ' . $uri);

        $ids = Node::resolvePath($uri);
        $item_id = array_pop($ids);

        return Lock::where('item_id', $item_id)->where('token', $lockInfo->token)->delete() === 1;
    }
}
