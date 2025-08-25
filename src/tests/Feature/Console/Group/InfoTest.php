<?php

namespace Tests\Feature\Console\Group;

use App\Group;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InfoTest extends TestCase
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

        $code = \Artisan::call("group:info unknown@unknown.org");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("Group unknown@unknown.org does not exist.", $output);

        // A group without members
        $group = $this->getTestGroup('group-test@kolabnow.com');

        $expected = "Id: {$group->id}\nEmail: {$group->email}\nStatus: {$group->status}";

        $code = \Artisan::call("group:info {$group->email}");
        $output = trim(\Artisan::output());
        $this->assertSame(0, $code);
        $this->assertSame($expected, $output);

        // Group with members
        $group->setAddresses(['test@member.com']);

        $expected .= "\nMember: test@member.com";

        $code = \Artisan::call("group:info {$group->email}");
        $output = trim(\Artisan::output());
        $this->assertSame(0, $code);
        $this->assertSame($expected, $output);
    }
}
