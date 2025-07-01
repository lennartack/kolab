<?php

namespace Tests\Feature\Jobs\SharedFolder;

use App\Jobs\SharedFolder\CreateJob;
use App\SharedFolder;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateTest extends TestCase
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

        // Test unknown folder
        $job = (new CreateJob(123))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased(delay: 5);

        $folder = $this->getTestSharedFolder(
            'folder-test@' . \config('app.domain'),
            ['status' => SharedFolder::STATUS_NEW]
        );

        $this->assertFalse($folder->isLdapReady());
        $this->assertFalse($folder->isImapReady());
        $this->assertFalse($folder->isActive());

        \config(['app.with_imap' => true]);
        \config(['app.with_ldap' => true]);

        // Test shared folder creation
        IMAP::shouldReceive('createSharedFolder')->once()->with($folder)->andReturn(true);
        LDAP::shouldReceive('createSharedFolder')->once()->with($folder)->andReturn(true);

        $job = (new CreateJob($folder->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        $folder->refresh();

        $this->assertTrue($folder->isLdapReady());
        $this->assertTrue($folder->isImapReady());
        $this->assertTrue($folder->isActive());

        // Test folder deleted
        $folder->delete();

        $job = (new CreateJob($folder->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        // TODO: Test failures on domain sanity checks
        // TODO: Test partial execution, i.e. only IMAP or only LDAP
    }
}
