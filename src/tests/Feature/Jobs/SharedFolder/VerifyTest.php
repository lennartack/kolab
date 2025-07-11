<?php

namespace Tests\Feature\Jobs\SharedFolder;

use App\Jobs\SharedFolder\VerifyJob;
use App\SharedFolder;
use App\Support\Facades\IMAP;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VerifyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestSharedFolder('folder-test@kolabnow.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestSharedFolder('folder-test@kolabnow.com');

        parent::tearDown();
    }

    /**
     * Test job handle
     */
    public function testHandle(): void
    {
        Queue::fake();

        // Test non-existing folder ID
        $job = (new VerifyJob(123))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailedWith("Shared folder 123 could not be found in the database.");

        // Test existing folder
        $folder = $this->getTestSharedFolder('folder-test@kolabnow.com', ['status' => SharedFolder::STATUS_NEW]);

        $this->assertFalse($folder->isImapReady());

        // Test successful verification
        IMAP::shouldReceive('verifySharedFolder')->once()->with($folder->getSetting('folder'))->andReturn(true);

        $job = new VerifyJob($folder->id);
        $job->handle();

        $folder->refresh();
        $this->assertTrue($folder->isImapReady());

        $folder->status ^= SharedFolder::STATUS_IMAP_READY;
        $folder->save();

        // Test unsuccessful verification
        IMAP::shouldReceive('verifySharedFolder')->once()->with($folder->getSetting('folder'))->andReturn(false);

        $job = new VerifyJob($folder->id);
        $job->handle();

        $folder->refresh();
        $this->assertFalse($folder->isImapReady());
    }
}
