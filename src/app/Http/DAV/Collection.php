<?php

namespace App\Http\DAV;

use App\Backends\Storage;
use App\Fs\Item;
use Illuminate\Support\Facades\DB;
use Sabre\DAV\Exception;
use Sabre\DAV\ICollection;
use Sabre\DAV\ICopyTarget;
use Sabre\DAV\IMoveTarget;
use Sabre\DAV\INode;
use Sabre\DAV\INodeByPath;
use Sabre\DAV\IProperties;

/**
 * Sabre DAV Collection interface implemetation
 */
class Collection extends Node implements ICollection, ICopyTarget, IMoveTarget, INodeByPath, IProperties
{
    /**
     * Checks if a child-node exists.
     *
     * It is generally a good idea to try and override this. Usually it can be optimized.
     *
     * @param string $name
     *
     * @return bool
     */
    public function childExists($name)
    {
        $path = $this->nodePath($name);

        \Log::debug('[DAV] CHILD-EXISTS: ' . $path);

        try {
            $this->fsItemForPath($path);
            return true;
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return false;
        }
    }

    /**
     * Copies a node into this collection.
     *
     * It is up to the implementors to:
     *   1. Create the new resource.
     *   2. Copy the data and any properties.
     *
     * If you return true from this function, the assumption
     * is that the copy was successful.
     * If you return false, sabre/dav will handle the copy itself.
     *
     * @param string $targetName New local file/collection name
     * @param string $sourcePath Full path to source node
     * @param INode  $sourceNode Source node itself
     * @param int    $depth      How many level of children to copy.
     *                           The value can be -1 (Sabre\DAV\Server::DEPTH_INFINITY) or a positive number including zero.
     *                           Zero means to only copy a shallow collection with props, but without children.
     *
     * @return bool
     */
    public function copyInto($targetName, $sourcePath, INode $sourceNode, int $depth)
    {
        $path = $this->nodePath($targetName);

        \Log::debug("[DAV] COPY-INTO: {$sourcePath} > {$path}, Depth:{$depth}");

        $item = $sourceNode->fsItem(); // @phpstan-ignore-line

        $item->copy($this->data, $targetName, $depth);

        $this->deleteCachedItem($path);

        return true;
    }

    /**
     * Creates a new file in the directory
     *
     * Data will either be supplied as a stream resource, or in certain cases
     * as a string. Keep in mind that you may have to support either.
     *
     * After succesful creation of the file, you may choose to return the ETag
     * of the new file here.
     *
     * The returned ETag must be surrounded by double-quotes (The quotes should
     * be part of the actual string).
     *
     * If you cannot accurately determine the ETag, you should not return it.
     * If you don't store the file exactly as-is (you're transforming it
     * somehow) you should also not return an ETag.
     *
     * This means that if a subsequent GET to this new file does not exactly
     * return the same contents of what was submitted here, you are strongly
     * recommended to omit the ETag.
     *
     * @param string          $name Name of the file
     * @param resource|string $data Initial payload
     *
     * @return string|null
     *
     * @throws Exception
     */
    public function createFile($name, $data = null)
    {
        $path = $this->nodePath($name);

        \Log::debug('[DAV] CREATE-FILE: ' . $path);

        if (str_starts_with($name, '.')) {
            throw new Exception\Forbidden('Hidden files are not accepted');
        }

        DB::beginTransaction();

        $file = Auth::$user->fsItems()->create(['type' => Item::TYPE_FILE]);
        $file->setProperty('name', $name);

        if ($parent = $this->data) {
            $parent->children()->attach($file->id);
        }

        // FIXME: For big files fileInput() can take a while, so use of transactions here might be a problem, or not?
        // FIXME: fileInput() will detect input mimetype, should we rather trust the request Content-Type?
        Storage::fileInput($data, [], $file);

        DB::commit();

        // Refresh the file to get an up-to-date ETag
        $file = new File($path, $this, $file);
        $file->refresh();

        return $file->getETag();
    }

    /**
     * Creates a new subdirectory
     *
     * @param string $name
     *
     * @throws Exception
     */
    public function createDirectory($name)
    {
        \Log::debug('[DAV] CREATE-DIRECTORY: ' . $this->nodePath($name));

        if (str_starts_with($name, '.')) {
            throw new Exception\Forbidden('Hidden files are not accepted');
        }

        DB::beginTransaction();

        $collection = Auth::$user->fsItems()->create(['type' => Item::TYPE_COLLECTION]);
        $collection->setProperty('name', $name);

        if ($parent = $this->data) {
            $parent->children()->attach($collection->id);
        }

        DB::commit();
    }

    /**
     * Deletes the current collection
     *
     * @throws \Exception
     */
    public function delete()
    {
        DB::beginTransaction();

        parent::delete();

        // Delete the files/folders inside
        // TODO: This may not be optimal for a case with a lot of files/folders
        // TODO: Maybe deleting a folder contents should be moved to a delete event observer
        $this->data->children()->where('type', Item::TYPE_COLLECTION)
            ->select('fs_items.*')
            ->selectRaw('(select value from fs_properties where fs_items.id = fs_properties.item_id'
                . ' and fs_properties.key = \'name\') as name')
            ->get()
            ->each(function ($folder) {
                $path = $this->nodePath($folder->name); // @phpstan-ignore-line
                $node = new self($path, $this, $folder);
                $node->delete();
            });

        $this->data->children()->delete();

        DB::commit();
    }

    /**
     * Returns an array with all the child nodes.
     *
     * @return INode[]
     *
     * @throws \Exception
     */
    public function getChildren()
    {
        \Log::debug('[DAV] GET-CHILDREN: ' . $this->path);

        $query = Auth::$user->fsItems()
            ->select('fs_items.*')
            ->whereNot('type', '&', Item::TYPE_INCOMPLETE);

        foreach (['name', 'size', 'mimetype'] as $key) {
            $query->selectRaw('(select value from fs_properties where fs_items.id = fs_properties.item_id'
                . " and fs_properties.key = '{$key}') as {$key}");
        }

        if ($parent = $this->data) {
            $query->join('fs_relations', 'fs_items.id', '=', 'fs_relations.related_id')
                ->where('fs_relations.item_id', $parent->id);
        } else {
            $query->leftJoin('fs_relations', 'fs_items.id', '=', 'fs_relations.related_id')
                ->whereNull('fs_relations.related_id');
        }

        return $query->orderBy('name')
            ->get()
            ->map(function ($item) {
                $class = $item->type == Item::TYPE_COLLECTION ? Collection::class : File::class;
                return new $class($this->nodePath($item), $this, $item);
            })
            ->all();
    }

    /**
     * Returns a child object, by its name.
     *
     * Generally its wise to override this, as this can usually be optimized
     *
     * This method must throw Sabre\DAV\Exception\NotFound if the node does not
     * exist.
     *
     * @param string $name
     *
     * @return INode
     *
     * @throws \Exception
     */
    public function getChild($name)
    {
        return $this->getNodeForPath($name);
    }

    /**
     * Returns the INode object for the requested path.
     *
     * In case where this collection can not retrieve the requested node
     * but also can not determine that the node does not exists,
     * null should be returned to signal that the caller should fallback
     * to walking the directory tree.
     *
     * @param string $name Node name (can include path)
     *
     * @return INode|null
     *
     * @throws \Exception
     */
    public function getNodeForPath($name)
    {
        $path = $this->nodePath($name);

        \Log::debug("[DAV] GET-NODE-FOR-PATH: {$path}");

        if (str_starts_with($name, '.') || str_contains($name, '/.')) {
            throw new Exception\NotFound("File not found: {$path}");
        }

        $item = $this->fsItemForPath($path);

        $class = $item->type == Item::TYPE_COLLECTION ? self::class : File::class;
        $parent = $this;
        $parent_path = preg_replace('|/[^/]+$|', '', $path);

        if ($parent_path != $this->path) {
            $parent = $this->fsItemForPath($parent_path);
        }

        return new $class($path, $parent, $item);
    }

    /**
     * Returns a list of properties for this node.
     *
     * The properties list is a list of property names the client requested,
     * encoded in clark-notation {xmlnamespace}tagname
     *
     * If the array is empty, it means 'all properties' were requested.
     *
     * Note that it's fine to liberally give properties back, instead of
     * conforming to the list of requested properties.
     * The Server class will filter out the extra.
     *
     * @param array $properties
     *
     * @return array
     */
    public function getProperties($properties)
    {
        $result = [];

        if (!empty($this->data->created_at)) {
            $result['{DAV:}creationdate'] = \Sabre\HTTP\toDate($this->data->created_at);
        }

        return $result;
    }

    /**
     * Moves a node into this collection.
     *
     * It is up to the implementors to:
     *   1. Create the new resource.
     *   2. Remove the old resource.
     *   3. Transfer any properties or other data.
     *
     * Generally you should make very sure that your collection can easily move
     * the node.
     *
     * If you don't, just return false, which will trigger sabre/dav to handle
     * the move itself. If you return true from this function, the assumption
     * is that the move was successful.
     *
     * @param string $targetName New local file/collection name
     * @param string $sourcePath Full path to source node
     * @param INode  $sourceNode Source node itself
     *
     * @return bool
     */
    public function moveInto($targetName, $sourcePath, INode $sourceNode)
    {
        $path = $this->nodePath($targetName);

        \Log::debug("[DAV] MOVE-INTO: {$sourcePath} > {$path}");

        $item = $sourceNode->fsItem(); // @phpstan-ignore-line

        $item->move($this->data, $targetName);

        $this->deleteCachedItem($path);

        return true;
    }

    /**
     * Updates properties on this node.
     *
     * This method received a PropPatch object, which contains all the
     * information about the update.
     *
     * To update specific properties, call the 'handle' method on this object.
     * Read the PropPatch documentation for more information.
     */
    public function propPatch(\Sabre\DAV\PropPatch $propPatch)
    {
        \Log::debug('[DAV] PROP-PATCH: ' . $this->path);

        // not supported
        // FIXME: Should we throw an exception?
    }
}
