<?php

namespace Tests\Feature\Console\Fs;

use App\Backends\Storage;
use App\Fs\Chunk;
use App\Fs\Item;
use Tests\TestCaseFs;

class ExpungeTest extends TestCaseFs
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('expungetest@kolabnow.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('expungetest@kolabnow.com');

        parent::tearDown();
    }

    /**
     * Test fs:expunge command
     */
    public function testHandle(): void
    {
        $user = $this->getTestUser('expungetest@kolabnow.com');
        $folder1 = $this->getTestCollection($user, 'folder');
        $file1 = $this->getTestFile($user, 'test1.txt', 'Test content1', ['mimetype' => 'text/plain']);
        $file2 = $this->getTestFile($user, 'test2.txt', 'Test content2', ['mimetype' => 'text/plain']);

        // Expect no changes
        $code = \Artisan::call('fs:expunge');
        $this->assertSame(0, $code);
        $this->assertSame('', trim(\Artisan::output()));
        $this->assertCount(3, $user->fsItems()->get());

        // Test expunging of soft-deleted files
        $file1->delete();

        $code = \Artisan::call('fs:expunge');
        $this->assertSame(0, $code);
        $this->assertNull(Item::find($file1->id));
        $this->assertCount(2, $user->fsItems()->get());

        // TODO: Test expunging of orphaned chunks
        $file2->chunks()->create([
            'chunk_id' => '123456789',
            'sequence' => 2,
            'size' => 2,
        ]);
        $chunk = Chunk::where('chunk_id', '123456789')->first();
        $chunk->update(['deleted_at' => now()->subSeconds(Storage::UPLOAD_TTL + 5)]);

        $code = \Artisan::call('fs:expunge');
        $this->assertSame(0, $code);
        $this->assertNull(Chunk::where('chunk_id', $chunk->chunk_id)->first());

        // Delete user (this does not remove files)
        $user->delete();
        $this->assertCount(2, $user->fsItems()->get());

        $code = \Artisan::call('fs:expunge');
        $this->assertSame(0, $code);
        $this->assertCount(2, $user->fsItems()->get());

        // Force-delete user (should orphan files/collections and then expunge them)
        $user->forceDelete();
        $this->assertCount(2, Item::whereNull('user_id')->get());

        $code = \Artisan::call('fs:expunge');
        $this->assertSame(0, $code);
        $this->assertCount(0, Item::all());
    }
}
