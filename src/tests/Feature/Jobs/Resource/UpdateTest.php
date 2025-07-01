<?php

namespace Tests\Feature\Jobs\Resource;

use App\Jobs\Resource\UpdateJob;
use App\Resource;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UpdateTest extends TestCase
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

        // Test non-existing resource ID
        $job = (new UpdateJob(123))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailedWith("Resource 123 could not be found in the database.");

        $resource = $this->getTestResource(
            'resource-test@' . \config('app.domain'),
            ['status' => Resource::STATUS_NEW | Resource::STATUS_LDAP_READY | Resource::STATUS_IMAP_READY]
        );

        // Run the update with some new config
        $resource->setConfig(['invitation_policy' => 'accept']);

        // TODO: Make the test working with various with_imap/with_ldap combinations
        \config(['app.with_imap' => true]);
        \config(['app.with_ldap' => true]);

        IMAP::shouldReceive('updateResource')->once()->with($resource, [])->andReturn(true);
        LDAP::shouldReceive('updateResource')->once()->with($resource)->andReturn(true);

        $job = new UpdateJob($resource->id);
        $job->handle();

        // Test deleted resource
        $resource->delete();

        $job = (new UpdateJob($resource->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();
    }
}
