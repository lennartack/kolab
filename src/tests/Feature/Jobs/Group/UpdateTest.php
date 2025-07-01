<?php

namespace Tests\Feature\Jobs\Group;

use App\Group;
use App\Jobs\Group\UpdateJob;
use App\Support\Facades\LDAP;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestGroup('group@kolab.org');
    }

    protected function tearDown(): void
    {
        $this->deleteTestGroup('group@kolab.org');

        parent::tearDown();
    }

    /**
     * Test job handle
     */
    public function testHandle(): void
    {
        Queue::fake();

        // Test non-existing group ID
        $job = (new UpdateJob(123))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailedWith("Group 123 could not be found in the database.");

        // Create the group
        $group = $this->getTestGroup('group@kolab.org', ['members' => []]);

        \config(['app.with_ldap' => true]);

        // Group not LDAP_READY
        $job = (new UpdateJob($group->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        // Group is LDAP_READY
        $group->status |= Group::STATUS_LDAP_READY;
        $group->save();

        LDAP::shouldReceive('connect');
        LDAP::shouldReceive('getGroup')->once()->with($group->email)->andReturn(['test' => 'test']);
        LDAP::shouldReceive('updateGroup')->once()->with($group)->andReturn(true);
        LDAP::shouldReceive('disconnect');

        $job = (new UpdateJob($group->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        // Test that suspended group is removed from LDAP
        $group->suspend();

        LDAP::shouldReceive('connect');
        LDAP::shouldReceive('getGroup')->once()->with($group->email)->andReturn(['test' => 'test']);
        LDAP::shouldReceive('deleteGroup')->once()->with($group)->andReturn(true);
        LDAP::shouldReceive('disconnect');

        $job = (new UpdateJob($group->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        // Test that unsuspended group is added back to LDAP
        $group->unsuspend();

        LDAP::shouldReceive('connect');
        LDAP::shouldReceive('getGroup')->once()->with($group->email)->andReturn(null);
        LDAP::shouldReceive('createGroup')->once()->with($group)->andReturn(true);
        LDAP::shouldReceive('disconnect');

        $job = (new UpdateJob($group->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();
    }
}
