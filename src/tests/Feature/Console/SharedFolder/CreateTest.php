<?php

namespace Tests\Feature\Console\SharedFolder;

use App\SharedFolder;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($folder = SharedFolder::withTrashed()->where('name', 'Tasks')->first()) {
            $folder->forceDelete();
        }
    }

    protected function tearDown(): void
    {
        if ($folder = SharedFolder::withTrashed()->where('name', 'Tasks')->first()) {
            $folder->forceDelete();
        }

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
        $code = \Artisan::call("sharedfolder:create unknown.org test");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("No such domain unknown.org.", $output);

        // Public domain not allowed
        $code = \Artisan::call("sharedfolder:create kolabnow.com Test --type=event");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("Domain kolabnow.com is public.", $output);

        // Invalid type
        $code = \Artisan::call("sharedfolder:create kolab.org Test --type=unknown");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("The specified type is invalid.", $output);

        \config(['app.shared_folder_types' => ['mail', 'task']]);

        // Invalid acl
        $code = \Artisan::call("sharedfolder:create kolab.org Test --type=task --acl=\"anyone,unknown\"");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame("Invalid --acl entry.", $output);
        $this->assertSame(0, SharedFolder::where('name', 'Test')->count());

        // Create a folder
        $acl = '--acl="anyone, read-only" --acl="jack@kolab.org, full"';
        $code = \Artisan::call("sharedfolder:create kolab.org Tasks --type=task {$acl}");
        $output = trim(\Artisan::output());

        $folder = SharedFolder::find($output);

        $this->assertSame(0, $code);
        $this->assertSame('Tasks', $folder->name);
        $this->assertSame('task', $folder->type);
        $this->assertSame($user->wallets->first()->id, $folder->wallet()->id);
        $this->assertSame(['anyone, read-only', 'jack@kolab.org, full'], $folder->getConfig()['acl']);

        // Existing folder
        $code = \Artisan::call("sharedfolder:create kolab.org Tasks");
        $output = trim(\Artisan::output());
        $this->assertSame(1, $code);
        $this->assertSame("The specified name is not available.", $output);
    }
}
