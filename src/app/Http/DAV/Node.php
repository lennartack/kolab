<?php

namespace App\Http\DAV;

use App\Fs\Item;
use Illuminate\Support\Facades\Context;
use Sabre\DAV\Exception;
use Sabre\DAV\INode;

/**
 * Sabre DAV Node interface implementation
 */
class Node implements INode
{
    /** @var string The path to the current node */
    protected $path;

    /** @var ?Item Internal node data (e.g. file/folder properties) */
    protected $data;

    /** @var ?Node Parent node */
    protected $parent;

    /**
     * Sets up the node, expects a full path name
     *
     * @param string $path   Node name with path
     * @param ?Node  $parent Parent node
     * @param ?Item  $data   Node data
     */
    public function __construct($path, $parent = null, $data = null)
    {
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/' . Auth::$user?->email;

        if ($path === $root) {
            $path = '';
        } elseif (str_starts_with($path, "{$root}/")) {
            $path = substr($path, strlen("{$root}/"));
        }

        $this->data = $data;
        $this->path = $path;
        $this->parent = $parent;
    }

    /**
     * Deletes the current node
     *
     * @throws \Exception
     */
    public function delete()
    {
        \Log::debug('[DAV] DELETE: ' . $this->path);

        // Here we're just marking the nodes as deleted, they will be removed from the
        // storage later with the fs:expunge command
        // FIXME: This will also bump the updated_at timestamp, should we prevent that?
        // Note: Deleting a collection is handled by Collection::delete()

        $this->data->delete();
    }

    /**
     * Get the filesystem item for the node
     */
    public function fsItem(): ?Item
    {
        return $this->data;
    }

    /**
     * Returns the last modification time
     *
     * @return int|null
     */
    public function getLastModified()
    {
        // FIXME: What about last-modified for folders? Should we return null?
        return $this->data?->updated_at?->getTimestamp();
    }

    /**
     * Returns the name of the node
     *
     * @return string
     */
    public function getName()
    {
        if ($this->path === '') {
            return '';
        }

        return array_last(explode('/', $this->path));
    }

    /**
     * Renames the node
     *
     * @param string $name The new name
     *
     * @throws \Exception
     */
    public function setName($name)
    {
        \Log::debug('[DAV] SET-NAME: ' . $this->path);

        $this->data->setProperty('name', $name);
    }

    /**
     * Get item identifiers for all items in a path
     *
     * @param string $path    Node location
     * @param bool   $nothrow Don't throw NotFound exception, return as many nodes as possible
     *
     * @return array<string> List of item identifiers
     *
     * @throws Exception\NotFound For not found non-root folder/file
     */
    public static function resolvePath(string $path, $nothrow = false): array
    {
        if (!strlen($path)) {
            if ($nothrow) {
                return [];
            }

            throw new Exception\NotFound("Unsupported location");
        }

        $path = explode('/', $path);
        $count = count($path);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $item_path = implode('/', array_slice($path, 0, $i + 1));

            try {
                $item = self::fsItemForPath($item_path);
            } catch (Exception\NotFound $e) {
                if ($nothrow) {
                    return $result;
                }

                throw $e;
            }

            $result[] = $item->id;
        }

        return $result;
    }

    /**
     * Return DAV path for a filesystem item (file or folder)
     *
     * @param string|Item $item
     */
    protected function nodePath($item): string
    {
        return (strlen($this->path) ? $this->path . '/' : '')
            . (is_string($item) ? $item : $item->name); // @phpstan-ignore-line
    }

    /**
     * Find the filesystem item record for the current path
     *
     * @return ?Item Found Item or Null for empty path (root)
     *
     * @throws Exception\NotFound For not found non-root folder/file
     */
    protected static function fsItemForPath(string $path): ?Item
    {
        if (!strlen($path)) {
            return null;
        }

        if (($item = self::getCachedItem($path)) !== null) {
            if ($item === false) {
                throw new Exception\NotFound("Unknown location: {$path}");
            }
            return $item;
        }

        $path = explode('/', $path);
        $count = count($path);
        $parent = $item = null;

        for ($i = 0; $i < $count; $i++) {
            $item_path = implode('/', array_slice($path, 0, $i + 1));
            $item_name = $path[$i];

            $item = self::getCachedItem($item_path);

            if ($item === null) {
                $query = Auth::$user->fsItems()->select('fs_items.*', 'fs_properties.value as name')
                    ->join('fs_properties', 'fs_items.id', '=', 'fs_properties.item_id')
                    ->whereNot('type', '&', Item::TYPE_INCOMPLETE)
                    ->where('key', 'name')
                    ->where('value', $item_name); // TODO: Make sure it's a case-sensitive match?

                if ($parent) {
                    $query->join('fs_relations', 'fs_items.id', '=', 'fs_relations.related_id')
                        ->where('fs_relations.item_id', $parent->id);
                } else {
                    $query->leftJoin('fs_relations', 'fs_items.id', '=', 'fs_relations.related_id')
                        ->whereNull('fs_relations.related_id');
                }

                $item = $query->first();

                // Get file properties
                // TODO: In some requests context (e.g. LOCK/UNLOCK) we don't need these extra properties
                if ($item && $item->type == Item::TYPE_FILE) {
                    $item->properties()->whereIn('key', ['size', 'mimetype'])->each(function ($prop) use ($item) {
                        $item->{$prop->key} = $prop->value;
                    });
                }
            }

            // Cache the last item and its parent
            if ($item !== false && $i >= $count - 2) {
                self::setCachedItem($item_path, $item ?? false);
            }

            if (!$item) {
                throw new Exception\NotFound("Unknown location: {$item_path}");
            }

            $parent = $item;
        }

        return $item;
    }

    /**
     * Delete cached filesystem item
     */
    protected function deleteCachedItem(string $path): void
    {
        Context::forgetHidden('fs:' . $path);
    }

    /**
     * Get cached filesystem item
     *
     * @return Item|false|null
     */
    protected static function getCachedItem(string $path)
    {
        return Context::getHidden('fs:' . $path);
    }

    /**
     * Store cached filesystem item into a request context
     *
     * @param string          $path Item path
     * @param Item|false|null $item Item or false if we know it does not exist
     */
    protected static function setCachedItem(string $path, $item): void
    {
        // A very common sequence of Sabre DAV Server actions is childExists(), getChild(), then action on it.
        // So, by caching the first lookup we can save quite a lot of time.
        // Note: It will often call getChild() even if childExists() returned false,
        // that's why we store all lookup results including `false`.
        Context::addHidden('fs:' . $path, $item);
    }
}
