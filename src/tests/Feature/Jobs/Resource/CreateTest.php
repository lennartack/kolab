<?php

namespace Tests\Feature\Jobs\Resource;

use App\Jobs\Resource\CreateJob;
use App\Resource;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestResource('resource-test@' . \config('app.domain'));
    }

    protected function tearDown(): void
    {
        $this->deleteTestResource('resource-test@' . \config('app.domain'));

        parent::tearDown();
    }

    /**
     * Test job handle
     */
    public function testHandle(): void
    {
        Queue::fake();

        // Test unknown resource
        $job = (new CreateJob(123))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased();

        $resource = $this->getTestResource(
            'resource-test@' . \config('app.domain'),
            ['status' => Resource::STATUS_NEW]
        );

        $this->assertFalse($resource->isLdapReady());
        $this->assertFalse($resource->isImapReady());
        $this->assertFalse($resource->isActive());

        // TODO: Make the test working with various with_imap/with_ldap combinations
        \config(['app.with_imap' => true]);
        \config(['app.with_ldap' => true]);

        // Test resource creation
        IMAP::shouldReceive('createResource')->once()->with($resource)->andReturn(true);
        LDAP::shouldReceive('createResource')->once()->with($resource)->andReturn(true);

        $job = (new CreateJob($resource->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        $resource->refresh();

        $this->assertTrue($resource->isLdapReady());
        $this->assertTrue($resource->isImapReady());
        $this->assertTrue($resource->isActive());

        // TODO: Test case when IMAP or LDAP method fails

        // Test a resource actually deleted
        $resource->delete();

        $job = (new CreateJob($resource->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        // TODO: Test failures on domain sanity checks
        // TODO: Test partial execution, i.e. only IMAP or only LDAP
    }
}
