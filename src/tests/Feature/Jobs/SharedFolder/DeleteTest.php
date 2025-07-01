<?php

namespace Tests\Feature\Jobs\SharedFolder;

use App\Jobs\SharedFolder\DeleteJob;
use App\Jobs\SharedFolder\UpdateJob;
use App\SharedFolder;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestSharedFolder('folder-test@' . \config('app.domain'));
    }

    protected function tearDown(): void
    {
        $this->deleteTestSharedFolder('folder-test@' . \config('app.domain'));

        parent::tearDown();
    }

    /**
     * Test job handle
     */
    public function testHandle(): void
    {
        Queue::fake();

        // Test non-existing folder ID
        $job = (new DeleteJob(123))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailedWith("Shared folder 123 could not be found in the database.");

        $folder = $this->getTestSharedFolder('folder-test@' . \config('app.domain'), [
            'status' => SharedFolder::STATUS_NEW | SharedFolder::STATUS_IMAP_READY | SharedFolder::STATUS_LDAP_READY,
        ]);

        // TODO: Make the test working with various with_imap/with_ldap combinations
        \config(['app.with_imap' => true]);
        \config(['app.with_ldap' => true]);

        // Test deleting not deleted folder
        $job = (new DeleteJob($folder->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailedWith("Shared folder {$folder->id} is not deleted.");

        $folder->deleted_at = \now();
        $folder->saveQuietly();
        Queue::fake();

        // Test successful deletion
        IMAP::shouldReceive('deleteSharedFolder')->once()->with($folder)->andReturn(true);
        LDAP::shouldReceive('deleteSharedFolder')->once()->with($folder)->andReturn(true);

        $job = new DeleteJob($folder->id);
        $job->handle();

        $folder->refresh();

        $this->assertFalse($folder->isLdapReady());
        $this->assertFalse($folder->isImapReady());
        $this->assertTrue($folder->isDeleted());

        Queue::assertPushed(UpdateJob::class, 0);

        // Test deleting already deleted folder
        $job = (new DeleteJob($folder->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();
    }
}
