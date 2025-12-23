<?php

namespace Tests\Feature\Console\User;

use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use App\Delegation;

class DelegateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('delegator@kolabnow.com');
        $this->deleteTestUser('delegatee@kolabnow.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('delegator@kolabnow.com');
        $this->deleteTestUser('delegatee@kolabnow.com');

        parent::tearDown();
    }

    /**
     * Test the command
     */
    public function testHandle(): void
    {
        Queue::fake();

        // Non-existing user
        $code = \Artisan::call("user:delegate unknown@unknown.org unknown@unknown.org");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame("User not found.", $output);

        $delegator = $this->getTestUser('delegator@kolabnow.com');
        $delegatee = $this->getTestUser('delegatee@kolabnow.com');

        $code = \Artisan::call("user:delegate {$delegator->email} {$delegatee->email}");
        $output = trim(\Artisan::output());

        $this->assertSame($delegatee->email, $delegator->delegatees()->first()->email);
        $this->assertSame('', $output);
        $this->assertSame(0, $code);
    }
}
