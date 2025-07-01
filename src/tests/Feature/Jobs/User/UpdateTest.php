<?php

namespace Tests\Feature\Jobs\User;

use App\Jobs\User\UpdateJob;
use App\Support\Facades\IMAP;
use App\Support\Facades\LDAP;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('new-job-user@' . \config('app.domain'));
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('new-job-user@' . \config('app.domain'));

        parent::tearDown();
    }

    /**
     * Test job handle
     */
    public function testHandle(): void
    {
        // Ignore any jobs created here (e.g. on setAliases() use)
        Queue::fake();

        $user = $this->getTestUser('new-job-user@' . \config('app.domain'), [
            'status' => User::STATUS_ACTIVE | User::STATUS_IMAP_READY | User::STATUS_LDAP_READY,
        ]);

        \config(['app.with_ldap' => true]);
        \config(['app.with_imap' => true]);

        IMAP::shouldReceive('updateUser')->once()->with($user)->andReturn(true);
        LDAP::shouldReceive('updateUser')->once()->with($user)->andReturn(true);

        // Test normal update
        $job = (new UpdateJob($user->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        // Test deleted user
        $user->delete();
        $job = (new UpdateJob($user->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        // Test job failure (user unknown), the job will be released
        $job = (new UpdateJob(123))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased(delay: 5);

        // TODO: Test IMAP, e.g. quota change
    }
}
