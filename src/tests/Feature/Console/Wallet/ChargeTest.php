<?php

namespace Tests\Feature\Console\Wallet;

use App\Jobs\Wallet\CheckJob;
use App\Wallet;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChargeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('wallet-charge@kolabnow.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('wallet-charge@kolabnow.com');

        parent::tearDown();
    }

    /**
     * Test command run for a specified wallet
     */
    public function testHandleSingle(): void
    {
        $user = $this->getTestUser('wallet-charge@kolabnow.com');
        $wallet = $user->wallets()->first();
        $wallet->balance = 0;
        $wallet->save();

        Queue::fake();

        // Non-existing wallet ID
        $this->artisan('wallet:charge 123')
            ->assertExitCode(1)
            ->expectsOutput("Wallet not found.");

        Queue::assertNothingPushed();

        // The wallet has no entitlements, expect no charge and no check
        $this->artisan('wallet:charge ' . $wallet->id)
            ->assertExitCode(0);

        Queue::assertPushed(CheckJob::class, 1);
        Queue::assertPushed(CheckJob::class, static function ($job) use ($wallet) {
            $job_wallet_id = TestCase::getObjectProperty($job, 'walletId');
            return $job_wallet_id === $wallet->id;
        });
    }

    /**
     * Test command run for all wallets
     */
    public function testHandleAll(): void
    {
        $user1 = $this->getTestUser('john@kolab.org');
        $wallet1 = $user1->wallets()->first();

        $user2 = $this->getTestUser('fred@' . \config('app.domain'));
        $wallet2 = $user2->wallets()->first();

        Queue::fake();

        $this->artisan('wallet:charge')->assertExitCode(0);

        Queue::assertPushed(CheckJob::class, 4);
        Queue::assertPushed(CheckJob::class, static function ($job) use ($wallet1) {
            $job_wallet_id = TestCase::getObjectProperty($job, 'walletId');
            return $job_wallet_id === $wallet1->id;
        });
        Queue::assertPushed(CheckJob::class, static function ($job) use ($wallet2) {
            $job_wallet_id = TestCase::getObjectProperty($job, 'walletId');
            return $job_wallet_id === $wallet2->id;
        });
    }
}
