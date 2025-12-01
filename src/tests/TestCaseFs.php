<?php

namespace Tests;

use App\Fs\Item;
use App\Fs\Property;
use App\Support\Facades\Storage;
use App\User;
use App\Utils;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Illuminate\Testing\TestResponse;

/**
 * @group files
 */
class TestCaseFs extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Item::query()->forceDelete();
    }

    protected function tearDown(): void
    {
        Item::query()->forceDelete();

        $disk = LaravelStorage::disk(\config('filesystems.default'));
        foreach ($disk->listContents('') as $dir) {
            $disk->deleteDirectory($dir->path());
        }

        parent::tearDown();
    }

    /**
     * Create a test file.
     *
     * @param User         $user    File owner
     * @param string       $name    File name
     * @param string|array $content File content
     * @param array        $props   Extra file properties
     */
    protected function getTestFile(User $user, string $name, $content = [], $props = []): Item
    {
        $disk = LaravelStorage::disk(\config('filesystems.default'));

        $file = $user->fsItems()->create(['type' => Item::TYPE_FILE]);
        $size = 0;

        if (is_array($content) && empty($content)) {
            // do nothing, we don't need the body here
        } else {
            foreach ((array) $content as $idx => $chunk) {
                $chunkId = Utils::uuidStr();
                $path = Storage::chunkLocation($chunkId, $file);

                $disk->write($path, $chunk);

                $size += strlen($chunk);

                $file->chunks()->create([
                    'chunk_id' => $chunkId,
                    'sequence' => $idx,
                    'size' => strlen($chunk),
                ]);
            }
        }

        $properties = [
            'name' => $name,
            'size' => $size,
            'mimetype' => 'application/octet-stream',
        ];

        $file->setProperties($props + $properties);

        return $file;
    }

    /**
     * Create a test collection.
     *
     * @param User   $user  File owner
     * @param string $name  File name
     * @param array  $props Extra collection properties
     */
    protected function getTestCollection(User $user, string $name, $props = []): Item
    {
        $collection = $user->fsItems()->create(['type' => Item::TYPE_COLLECTION]);

        $properties = [
            'name' => $name,
        ];

        $collection->setProperties($props + $properties);

        return $collection;
    }

    /**
     * Get contents of a test file.
     *
     * @param Item $file File record
     */
    protected function getTestFileContent(Item $file): string
    {
        $content = '';

        $file->chunks()->orderBy('sequence')->get()->each(static function ($chunk) use ($file, &$content) {
            $disk = LaravelStorage::disk(\config('filesystems.default'));
            $path = Storage::chunkLocation($chunk->chunk_id, $file);

            $content .= $disk->read($path);
        });

        return $content;
    }

    /**
     * Create a test file permission.
     *
     * @param Item   $file       The file
     * @param User   $user       File owner
     * @param string $permission File permission
     *
     * @return Property File permission property
     */
    protected function getTestFilePermission(Item $file, User $user, string $permission): Property
    {
        $shareId = 'share-' . Utils::uuidStr();

        return $file->properties()->create([
            'key' => $shareId,
            'value' => "{$user->email}:{$permission}",
        ]);
    }

    /**
     * Invoke a HTTP request with a custom raw body
     *
     * @param ?User  $user    Authenticated user
     * @param string $method  Request method (POST,  PUT)
     * @param string $uri     Request URL
     * @param array  $headers Request headers
     * @param string $content Raw body content
     *
     * @return TestResponse HTTP Response object
     */
    protected function sendRawBody(?User $user, string $method, string $uri, array $headers, string $content)
    {
        $headers['Content-Length'] = strlen($content);

        $server = $this->transformHeadersToServerVars($headers);
        $cookies = $this->prepareCookiesForRequest();

        if ($user) {
            return $this->actingAs($user)->call($method, $uri, [], $cookies, [], $server, $content);
        }
        // TODO: Make sure this does not use "acting user" set earlier
        return $this->call($method, $uri, [], $cookies, [], $server, $content);
    }
}
