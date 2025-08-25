<?php

namespace Tests\Feature\Console\Group;

use App\Group;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RemoveMemberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestGroup('group-test@kolabnow.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestGroup('group-test@kolabnow.com');

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

        // Non-existing group
        $code = \Artisan::call("group:remove-member test@group.com member@group.com");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame("Group test@group.com does not exist.", $output);

        $group = Group::create(['email' => 'group-test@kolabnow.com']);
        $group->setAddresses(['member1@gmail.com', 'member2@gmail.com']);

        // Existing group, non-existing member
        $code = \Artisan::call("group:remove-member {$group->email} nonexisting@gmail.com");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame("Member nonexisting@gmail.com not found in the group.", $output);

        // Existing group, existing member
        $code = \Artisan::call("group:remove-member {$group->email} member1@gmail.com");
        $output = trim(\Artisan::output());

        $this->assertSame(0, $code);
        $this->assertSame('', $output);
        $this->assertSame(['member2@gmail.com'], $group->getAddresses());

        // Existing group, the last existing member
        $code = \Artisan::call("group:remove-member {$group->email} member2@gmail.com");
        $output = trim(\Artisan::output());

        $this->assertSame(0, $code);
        $this->assertSame('', $output);
        $this->assertSame([], $group->getAddresses());
    }
}
