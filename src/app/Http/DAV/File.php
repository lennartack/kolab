<?php

namespace App\Http\DAV;

use App\Backends\Storage;
use Sabre\DAV\Exception;
use Sabre\DAV\IFile;
use Sabre\DAV\IProperties;

/**
 * Sabre DAV File interface implementation
 */
class File extends Node implements IFile, IProperties
{
    /**
     * Returns the file content
     *
     * This method may either return a string or a readable stream resource
     *
     * @return mixed
     *
     * @throws \Exception
     */
    public function get()
    {
        \Log::debug('[DAV] GET: ' . $this->path);

        if (!in_array('filestream', stream_get_wrappers())) {
            stream_wrapper_register('filestream', FileStream::class);
        }

        $fp = fopen("filestream://{$this->data->id}", 'r');

        return $fp;
    }

    /**
     * Returns the mime-type for a file
     *
     * If null is returned, we'll assume application/octet-stream
     *
     * @return string|null
     */
    public function getContentType()
    {
        return $this->data?->mimetype ?? 'application/octet-stream'; // @phpstan-ignore-line
    }

    /**
     * Returns the ETag for a file
     *
     * An ETag is a unique identifier representing the current version of the file. If the file changes, the ETag MUST change.
     * The ETag is an arbitrary string, but MUST be surrounded by double-quotes.
     *
     * Return null if the ETag can not effectively be determined
     *
     * @return string|null
     */
    public function getETag()
    {
        return substr(md5($this->path . ':' . $this->data->size), 0, 16) // @phpstan-ignore-line
            . '-' . $this->data->updated_at->getTimestamp();
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
     * Returns the size of the node, in bytes
     *
     * @return int
     */
    public function getSize()
    {
        return (int) $this->data?->size; // @phpstan-ignore-line
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

    /**
     * Updates content of an existing file
     *
     * The data argument is a readable stream resource.
     *
     * After a succesful put operation, you may choose to return an ETag. The
     * etag must always be surrounded by double-quotes. These quotes must
     * appear in the actual string you're returning.
     *
     * Clients may use the ETag from a PUT request to later on make sure that
     * when they update the file, the contents haven't changed in the mean
     * time.
     *
     * If you don't plan to store the file byte-by-byte, and you return a
     * different object on a subsequent GET you are strongly recommended to not
     * return an ETag, and just return null.
     *
     * @param resource|string $data
     *
     * @return string|null
     *
     * @throws \Exception
     */
    public function put($data)
    {
        \Log::debug('[DAV] PUT: ' . $this->path);

        // TODO: fileInput() method creates a non-chunked file, we need another way.

        $result = Storage::fileInput($data, [], $this->data);

        // Refresh the internal state for getETag()
        $this->refresh();

        return $this->getETag();
    }

    /**
     * Refresh the internal state
     */
    public function refresh()
    {
        $this->data->refresh();
        $this->data->properties()->whereIn('key', ['name', 'size', 'mimetype'])->each(function ($prop) {
            $this->data->{$prop->key} = $prop->value;
        });

        $this->deleteCachedItem($this->path);
    }
}
