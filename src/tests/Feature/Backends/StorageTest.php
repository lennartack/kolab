<?php

namespace Tests\Feature\Backends;

use App\Backends\Storage;
use App\Fs\Item;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Tests\TestCaseFs;

class StorageTest extends TestCaseFs
{
    /**
     * Test Storage::fileInput() splitting the input into chunks
     */
    public function testFileInputChunking(): void
    {
        $user = $this->getTestUser('john@kolab.org');
        $file = $user->fsItems()->create(['type' => Item::TYPE_FILE | Item::TYPE_INCOMPLETE]);

        \config(['octane.swoole.options.package_max_length' => 4000]);

        $stream = fopen('php://memory', 'r+');
        $content = [str_repeat('0123', 1000), str_repeat('abcd', 1000), str_repeat('yu', 1000)];
        fwrite($stream, implode('', $content));
        rewind($stream);

        $result = Storage::fileInput($stream, [], $file);

        $file->refresh();
        $this->assertSame(['id' => $file->id], $result);
        $this->assertFalse($file->isIncomplete());
        $this->assertSame('10000', $file->getProperty('size'));
        $this->assertSame('text/plain', $file->getProperty('mimetype'));

        $chunks = $file->chunks()->orderBy('sequence')->get();
        $this->assertCount(3, $chunks);
        $this->assertSame(4000, $chunks[0]->size);
        $this->assertSame(4000, $chunks[1]->size);
        $this->assertSame(2000, $chunks[2]->size);

        $disk = LaravelStorage::disk(\config('filesystems.default'));
        $this->assertSame($content[0], $disk->read(Storage::chunkLocation($chunks[0]->chunk_id, $file)));
        $this->assertSame($content[1], $disk->read(Storage::chunkLocation($chunks[1]->chunk_id, $file)));
        $this->assertSame($content[2], $disk->read(Storage::chunkLocation($chunks[2]->chunk_id, $file)));
    }
}
