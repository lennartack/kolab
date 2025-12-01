<?php

namespace App\Http\DAV;

use App\Backends\Storage;
use App\Fs\Item;
use Illuminate\Support\Facades\Storage as LaravelStorage;

/*
 * StreamWrapper implementation for streaming file contents
 *
 * https://www.php.net/manual/en/class.streamwrapper.php
 */
class FileStream
{
    private $chunks;
    private $disk;
    private Item $item;
    private int $position = 0;
    private int $size = 0;

    /**
     * Stream opening handler.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        // We expect $path to be filestream://<item-id>
        $this->item = Item::find(explode('//', $path)[1]);
        $this->chunks = $this->item->chunks()->orderBy('sequence')->get();
        $this->disk = LaravelStorage::disk(\config('filesystems.default'));

        foreach ($this->chunks as $chunk) {
            $this->size += $chunk->size;
        }

        return true;
    }

    /**
     * Stream reading handler.
     */
    public function stream_read(int $count)
    {
        if ($this->position >= $this->size) {
            return false;
        }

        if ($count <= 0) {
            return '';
        }

        $output = '';
        $pos = 0;

        foreach ($this->chunks as $chunk) {
            if ($this->position <= $pos + $chunk->size) {
                $offset = $this->position - $pos;
                $length = min($count, $chunk->size - $offset);

                $path = Storage::chunkLocation($chunk->chunk_id, $this->item);
                $stream = $this->disk->readStream($path);
                $body = stream_get_contents($stream, $length, $offset);

                if ($body === false) {
                    // FIXME: Can we throw exceptions from a stream wrapper?
                    throw new \Exception("Failed to read Streamed file '{$this->item->id}'");
                }

                $output .= $body;
                $this->position += $length;

                if ($length >= $count) {
                    break;
                }
            }

            $pos += $chunk->size;
        }

        return $output;
    }

    /**
     * Stream EOF check handler. See feof().
     */
    public function stream_eof(): bool
    {
        return $this->position >= $this->size;
    }

    /**
     * Stream seeking handler. See fseek().
     */
    public function stream_seek(int $offset, int $whence): bool
    {
        switch ($whence) {
            case \SEEK_SET:
                $this->position = $offset;
                break;
            case \SEEK_CUR:
                $this->position += $offset;
                break;
            case \SEEK_END:
                $this->position = $this->size + $offset;
                break;
        }

        return true;
    }

    /**
     * Stream tell handler. See ftell().
     */
    public function stream_tell(): int
    {
        return $this->position;
    }

    /**
     * Stream writing handler.
     */
    public function stream_write(string $data): int
    {
        throw new \Exception("File stream is readonly");
    }
}
