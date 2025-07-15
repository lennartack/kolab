<?php

namespace Tests\Feature\Console\User;

use App\Domain;
use App\Entitlement;
use App\Package;
use App\Support\Facades\IMAP;
use App\Support\Facades\Roundcube;
use App\User;
use App\Wallet;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ForceDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('user@force-delete.com');
        $this->deleteTestDomain('force-delete.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('user@force-delete.com');
        $this->deleteTestDomain('force-delete.com');

        parent::tearDown();
    }

    /**
     * Test the command
     */
    public function testHandle(): void
    {
        // Non-existing user
        $this->artisan('user:force-delete unknown@unknown.org')
            ->assertExitCode(1);

        Queue::fake();
        $user = $this->getTestUser('user@force-delete.com');
        $domain = $this->getTestDomain('force-delete.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_HOSTED,
        ]);
        $package_kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $package_domain = Package::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $user->assignPackage($package_kolab);
        $domain->assignPackage($package_domain, $user);
        $wallet = $user->wallets()->first();
        $entitlements = $wallet->entitlements->pluck('id')->all();

        $this->assertCount(8, $entitlements);

        // Non-deleted user
        $this->artisan('user:force-delete user@force-delete.com')
            ->assertExitCode(1);

        $user->delete();

        $this->assertTrue($user->trashed());
        $this->assertTrue($domain->fresh()->trashed());

        // Deleted user
        IMAP::shouldReceive('verifyAccount')->once()->with($user->email)->andReturn(false);
        Roundcube::shouldReceive('deleteUser')->once()->with($user->email);

        $this->artisan("user:force-delete {$user->email}")
            ->assertExitCode(0);

        $this->assertCount(0, User::withTrashed()->where('email', 'user@force-delete.com')->get());
        $this->assertCount(0, Domain::withTrashed()->where('namespace', 'force-delete.com')->get());
        $this->assertCount(0, Wallet::where('id', $wallet->id)->get());
        $this->assertCount(0, Entitlement::withTrashed()->where('wallet_id', $wallet->id)->get());
        $this->assertCount(0, Entitlement::withTrashed()->where('entitleable_id', $user->id)->get());

        // Deleted user with an existing mailbox
        $user = $this->getTestUser('user@force-delete.com');
        $user->delete();

        IMAP::shouldReceive('verifyAccount')->once()->with($user->email)->andReturn(true);
        IMAP::shouldReceive('deleteUser')->once()->andReturn(true);
        Roundcube::shouldReceive('deleteUser')->once()->with($user->email);

        $this->artisan("user:force-delete {$user->email}")
            ->assertExitCode(0)
            ->expectsOutput('DONE');
    }
}
