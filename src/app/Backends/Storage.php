<?php

namespace App\Backends;

use App\Fs\Chunk;
use App\Fs\Item;
use App\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Storage
{
    /** @var int How long the resumable upload "token" is valid (in seconds) */
    public const UPLOAD_TTL = 60 * 60 * 6;

    /**
     * Check if we can connect to the backend
     *
     * @return bool True on success
     */
    public static function healthcheck(): bool
    {
        $disk = LaravelStorage::disk(\config('filesystems.default'));
        $disk->put('healthcheck', 'healthcheck');
        $disk->size('healthcheck');
        $disk->delete('healthcheck');
        return true;
    }

    /**
     * Delete a file.
     *
     * @param Item $file File object
     *
     * @throws \Exception
     */
    public static function fileDelete(Item $file): void
    {
        $disk = LaravelStorage::disk(\config('filesystems.default'));

        $path = $file->path . '/' . $file->id;

        // TODO: Deleting files might be slow, consider marking as deleted and async job

        $disk->deleteDirectory($path);

        $file->forceDelete();
    }

    /**
     * Delete a file chunk.
     *
     * @param Chunk $chunk File chunk object
     *
     * @throws \Exception
     */
    public static function fileChunkDelete(Chunk $chunk): void
    {
        $disk = LaravelStorage::disk(\config('filesystems.default'));

        $path = self::chunkLocation($chunk->chunk_id, $chunk->item);

        $disk->delete($path);

        $chunk->forceDelete();
    }

    /**
     * File download handler.
     *
     * @param Item $file File object
     *
     * @throws \Exception
     */
    public static function fileDownload(Item $file): StreamedResponse
    {
        $response = new StreamedResponse();

        $props = $file->getProperties(['name', 'size', 'mimetype']);

        // Prepare the file name for the Content-Disposition header
        $extension = pathinfo($props['name'], \PATHINFO_EXTENSION) ?: 'file';
        $fallbackName = str_replace('%', '', Str::ascii($props['name'])) ?: "file.{$extension}";
        $disposition = $response->headers->makeDisposition('attachment', $props['name'], $fallbackName);

        $response->headers->replace([
            'Content-Type' => $props['mimetype'],
            'Content-Disposition' => $disposition,
        ]);

        $response->setCallback(static function () use ($file) {
            $file->chunks()->orderBy('sequence')->get()->each(static function ($chunk) use ($file) {
                $disk = LaravelStorage::disk(\config('filesystems.default'));
                $path = Storage::chunkLocation($chunk->chunk_id, $file);

                $stream = $disk->readStream($path);

                fpassthru($stream);
                fclose($stream);
            });
        });

        return $response;
    }

    /**
     * File content getter.
     *
     * @param Item $file File object
     *
     * @throws \Exception
     */
    public static function fileFetch(Item $file): string
    {
        $output = '';

        $file->chunks()->orderBy('sequence')->get()->each(static function ($chunk) use ($file, &$output) {
            $disk = LaravelStorage::disk(\config('filesystems.default'));
            $path = Storage::chunkLocation($chunk->chunk_id, $file);

            $output .= $disk->read($path);
        });

        return $output;
    }

    /**
     * File upload handler
     *
     * @param resource $stream File input stream
     * @param array    $params Request parameters
     * @param ?Item    $file   The file object
     *
     * @return array File/Response attributes
     *
     * @throws \Exception
     */
    public static function fileInput($stream, array $params, ?Item $file = null): array
    {
        if (!empty($params['uploadId'])) {
            return self::fileInputResumable($stream, $params, $file);
        }

        $disk = LaravelStorage::disk(\config('filesystems.default'));

        $chunkId = Utils::uuidStr();

        $path = self::chunkLocation($chunkId, $file);

        $disk->writeStream($path, $stream);

        $fileSize = $disk->size($path);

        if ($file->type & Item::TYPE_INCOMPLETE) {
            $file->type -= Item::TYPE_INCOMPLETE;
            $file->save();
        }

        // Update the file type and size information
        $file->setProperties([
            'size' => $fileSize,
            // Pick the client-supplied mimetype if available, otherwise detect.
            'mimetype' => !empty($params['mimetype']) ? $params['mimetype'] : self::mimetype($path),
        ]);

        // Assign the node to the file, "unlink" any old nodes of this file
        $file->chunks()->delete();
        $file->chunks()->create([
            'chunk_id' => $chunkId,
            'sequence' => 0,
            'size' => $fileSize,
        ]);

        return ['id' => $file->id];
    }

    /**
     * Resumable file upload handler
     *
     * @param resource $stream File input stream
     * @param array    $params Request parameters
     * @param ?Item    $file   The file object
     *
     * @return array File/Response attributes
     *
     * @throws \Exception
     */
    protected static function fileInputResumable($stream, array $params, ?Item $file = null): array
    {
        // Initial request, save file metadata, return uploadId
        if ($params['uploadId'] == 'resumable') {
            if (empty($params['size']) || empty($file)) {
                throw new \Exception("Missing parameters of resumable file upload.");
            }

            $params['uploadId'] = Utils::uuidStr();

            $upload = [
                'fileId' => $file->id,
                'size' => $params['size'],
                'uploaded' => 0,
            ];

            if (!Cache::add('upload:' . $params['uploadId'], $upload, self::UPLOAD_TTL)) {
                throw new \Exception("Failed to create cache entry for resumable file upload.");
            }

            return [
                'uploadId' => $params['uploadId'],
                'uploaded' => 0,
                'maxChunkSize' => (\config('octane.swoole.options.package_max_length') ?: 10 * 1024 * 1024) - 8192,
            ];
        }

        $upload = Cache::get('upload:' . $params['uploadId']);

        if (empty($upload)) {
            throw new \Exception("Cache entry for resumable file upload does not exist.");
        }

        $file = Item::find($upload['fileId']);

        if (!$file) {
            throw new \Exception("Invalid fileId for resumable file upload.");
        }

        $from = $params['from'] ?? 0;

        // Sanity checks on the input parameters
        // TODO: Support uploading again a chunk that already has been uploaded?
        if ($from < $upload['uploaded'] || $from > $upload['uploaded'] || $from > $upload['size']) {
            throw new \Exception("Invalid 'from' parameter for resumable file upload.");
        }

        $disk = LaravelStorage::disk(\config('filesystems.default'));
        $chunkId = Utils::uuidStr();

        $path = self::chunkLocation($chunkId, $file);

        // Save the file chunk
        $disk->writeStream($path, $stream);

        // Detect file type using the first chunk
        if ($from == 0) {
            $upload['mimetype'] = self::mimetype($path);
            $upload['chunks'] = [];
        }

        $chunkSize = $disk->size($path);

        // Create the chunk record
        $file->chunks()->create([
            'chunk_id' => $chunkId,
            'sequence' => count($upload['chunks']),
            'size' => $chunkSize,
            'deleted_at' => \now(), // not yet active chunk
        ]);

        $upload['chunks'][] = $chunkId;
        $upload['uploaded'] += $chunkSize;

        // Update the file metadata after the upload of all chunks is completed
        if ($upload['uploaded'] >= $upload['size']) {
            if ($file->type & Item::TYPE_INCOMPLETE) {
                $file->type -= Item::TYPE_INCOMPLETE;
                $file->save();
            }

            // Update file metadata
            $file->setProperties([
                'size' => $upload['uploaded'],
                'mimetype' => $upload['mimetype'] ?: 'application/octet-stream',
            ]);

            // Assign uploaded chunks to the file, "unlink" any old chunks of this file
            $file->chunks()->delete();
            $file->chunks()->whereIn('chunk_id', $upload['chunks'])->restore();

            // TODO: Create a "cron" job to remove orphaned nodes from DB and the storage.
            // I.e. all with deleted_at set and older than UPLOAD_TTL

            // Delete the upload cache record
            Cache::forget('upload:' . $params['uploadId']);

            return ['id' => $file->id];
        }

        // Update the upload metadata
        Cache::put('upload:' . $params['uploadId'], $upload, self::UPLOAD_TTL);

        return ['uploadId' => $params['uploadId'], 'uploaded' => $upload['uploaded']];
    }

    /**
     * Get the file mime type.
     *
     * @param string $path File location
     *
     * @return string File mime type
     */
    protected static function mimetype(string $path): string
    {
        $disk = LaravelStorage::disk(\config('filesystems.default'));

        $mimetype = $disk->mimeType($path);

        // The mimetype may contain e.g. "; charset=UTF-8", remove this
        if ($mimetype) {
            return explode(';', $mimetype)[0];
        }

        return 'application/octet-stream';
    }

    /**
     * Node location in the storage
     *
     * @param string $chunkId Chunk identifier
     * @param Item   $file    File the chunk belongs to
     *
     * @return string Chunk location
     */
    public static function chunkLocation(string $chunkId, Item $file): string
    {
        return $file->path . '/' . $file->id . '/' . $chunkId;
    }
}
