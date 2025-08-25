<?php

namespace Tests\Feature\Console\Group;

use App\Group;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestGroup('group-test@kolab.org');
        $this->deleteTestGroup('group-testm@kolab.org');
    }

    protected function tearDown(): void
    {
        $this->deleteTestGroup('group-test@kolab.org');
        $this->deleteTestGroup('group-testm@kolab.org');

        parent::tearDown();
    }

    /**
     * Test command runs
     */
    public function testHandle(): void
    {
        Queue::fake();

        // Warning: We're not using artisan() here, as this will not
        // allow us to test "empty output" cases

        $user = $this->getTestUser('john@kolab.org');

        // Domain not existing
        $code = \Artisan::call("group:create testgroup@unknown.org");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("No such domain unknown.org.", $output);

        // Existing email
        $code = \Artisan::call("group:create jack@kolab.org");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("jack@kolab.org: The specified email is not available.", $output);

        // Existing email (of a user alias)
        $code = \Artisan::call("group:create jack.daniels@kolab.org");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("jack.daniels@kolab.org: The specified email is not available.", $output);

        // Public domain not allowed in the group email address
        $code = \Artisan::call("group:create group-test@kolabnow.com");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("Domain kolabnow.com is public.", $output);

        // Create a group without members
        $code = \Artisan::call("group:create group-test@kolab.org");
        $output = trim(\Artisan::output());
        $group = Group::where('email', 'group-test@kolab.org')->first();

        $this->assertSame(0, $code);
        $this->assertSame((string) $group->id, $output);
        $this->assertSame([], $group->getAddresses());
        $this->assertSame($user->wallets->first()->id, $group->wallet()->id);

        // Existing email (of a group)
        $code = \Artisan::call("group:create group-test@kolab.org");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("group-test@kolab.org: The specified email is not available.", $output);

        // Invalid member
        $code = \Artisan::call("group:create group-testm@kolab.org --member=invalid");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("invalid: The specified email address is invalid.", $output);

        // Valid members
        $code = \Artisan::call(
            "group:create group-testm@kolab.org --member=member1@kolabnow.com --member=member2@gmail.com"
        );
        $output = trim(\Artisan::output());
        $group = Group::where('email', 'group-testm@kolab.org')->first();
        $this->assertSame(0, $code);
        $this->assertSame((string) $group->id, $output);
        $this->assertSame(['member1@kolabnow.com', 'member2@gmail.com'], $group->getAddresses());
        $this->assertSame($user->wallets->first()->id, $group->wallet()->id);
    }
}
