<?php

namespace App\Backends\Storage;

use Illuminate\Support\Facades\Context;

/*
 * StreamWrapper implementation for streaming file contents with chunking.
 * The idea is that we use the input stream directly and do not create
 * a in-memory or temp file stream for separate chunks.
 *
 * https://www.php.net/manual/en/class.streamwrapper.php
 */
class FileInputStream
{
    private $id;
    private $stream;
    private int $end = 0;
    private int $position = 0;

    /**
     * Chunk registration.
     */
    public static function registerChunkStream($stream, string $fileId, string $chunkId, int $start, int $end)
    {
        Context::addHidden("{$fileId}-{$chunkId}", $stream);

        if (!in_array('fileinputstream', stream_get_wrappers())) {
            stream_wrapper_register('fileinputstream', self::class);
        }

        return fopen("fileinputstream://{$fileId}-{$chunkId}:{$start}-{$end}", 'r');
    }

    /**
     * Stream closing handler.
     */
    public function stream_close(): void
    {
        Context::forgetHidden($this->id);
    }

    /**
     * Stream opening handler.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        [$this->id, $params] = explode(':', explode('//', $path)[1]);
        [$start, $end] = explode('-', $params);

        $this->stream = Context::getHidden($this->id);
        $this->position = (int) $start;
        $this->end = (int) $end;

        return true;
    }

    /**
     * Stream reading handler.
     */
    public function stream_read(int $count)
    {
        if ($this->position >= $this->end || !$this->stream) {
            return false;
        }

        if ($count <= 0) {
            return '';
        }

        if ($this->position + $count > $this->end) {
            $count = $this->end - $this->position;
        }

        $output = stream_get_contents($this->stream, $count, $this->position);

        $this->position += is_string($output) ? strlen($output) : 0;

        return $output;
    }

    /**
     * Stream EOF check handler. See feof().
     */
    public function stream_eof(): bool
    {
        return $this->position >= $this->end || feof($this->stream);
    }

    /**
     * Stream seeking handler. See fseek().
     */
    public function stream_seek(int $offset, int $whence): bool
    {
        throw new \Exception("Seek not implemented");
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
        throw new \Exception("File input stream is readonly");
    }
}
