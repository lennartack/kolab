<?php

namespace Tests\Feature\Jobs\SharedFolder;

use App\Jobs\SharedFolder\UpdateJob;
use App\SharedFolder;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UpdateTest extends TestCase
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
        $job = (new UpdateJob(123))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailedWith("Shared folder 123 could not be found in the database.");

        $folder = $this->getTestSharedFolder(
            'folder-test@' . \config('app.domain'),
            ['status' => SharedFolder::STATUS_NEW | SharedFolder::STATUS_IMAP_READY | SharedFolder::STATUS_LDAP_READY]
        );

        // TODO: Make the test working with various with_imap=false
        \config(['app.with_imap' => true]);
        \config(['app.with_ldap' => true]);

        // Run the update job
        IMAP::shouldReceive('updateSharedFolder')->once()->with($folder, [])->andReturn(true);
        LDAP::shouldReceive('updateSharedFolder')->once()->with($folder)->andReturn(true);

        $job = (new UpdateJob($folder->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        // Test handling deleted folder
        $folder->delete();

        $job = (new UpdateJob($folder->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();
    }
}
