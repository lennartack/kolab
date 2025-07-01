<?php

namespace Tests\Feature\Console;

use App\Jobs\Mail\PasswordRetentionJob;
use App\Package;
use App\User;
use App\UserSetting;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PasswordRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('user1@retention.com');
        $this->deleteTestUser('user2@retention.com');
        $keys = ['password_update', 'max_password_age', 'password_expiration_warning'];
        UserSetting::whereIn('key', $keys)->delete();
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('user1@retention.com');
        $this->deleteTestUser('user2@retention.com');
        $keys = ['password_update', 'max_password_age', 'password_expiration_warning'];
        UserSetting::whereIn('key', $keys)->delete();

        parent::tearDown();
    }

    /**
     * Test the command
     *
     * @group mollie
     */
    public function testHandle(): void
    {
        Queue::fake();

        // Create some sample account
        $status = User::STATUS_IMAP_READY | User::STATUS_LDAP_READY;
        $owner = $this->getTestUser('user1@retention.com', ['status' => $status]);
        $user = $this->getTestUser('user2@retention.com', ['status' => $status]);
        $package_kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $owner->assignPackage($package_kolab);
        $owner->assignPackage($package_kolab, $user);

        $owner->created_at = now()->copy()->subMonths(3);
        $owner->save();
        $user->created_at = now()->copy()->subMonths(3);
        $user->save();

        Queue::fake();

        // Test with no policy
        $code = \Artisan::call("password:retention");
        $output = trim(\Artisan::output());
        $this->assertSame(0, $code);
        $this->assertSame("", $output);

        Queue::assertNothingPushed();

        // Test with the policy, the passwords expired already
        $owner->setSetting('max_password_age', '2');
        $user->setSetting('password_update', now()->copy()->subMonthsWithoutOverflow(2));

        $code = \Artisan::call("password:retention");
        $output = trim(\Artisan::output());
        $this->assertSame(0, $code);
        $this->assertSame("", $output);

        Queue::assertNothingPushed();
        $this->assertNotNull($user->getSetting('password_expired'));
        $this->assertNotNull($owner->getSetting('password_expired'));

        // $user's password is about to expire in 14 days
        $user->setSetting('password_update', now()->copy()->subMonthsWithoutOverflow(2)->addDays(14));

        // $owner's password is about to expire in 7 days
        $owner->created_at = now()->copy()->subMonthsWithoutOverflow(2)->addDays(7);
        $owner->save();

        // Test no warning for users with expired passwords
        $code = \Artisan::call("password:retention");
        $this->assertSame(0, $code);

        Queue::assertNothingPushed();

        $user->removeSetting('password_expired');
        $owner->removeSetting('password_expired');

        // Test notifications
        $code = \Artisan::call("password:retention");
        $output = trim(\Artisan::output());
        $this->assertSame(0, $code);

        Queue::assertPushed(PasswordRetentionJob::class, 2);
        Queue::assertPushed(PasswordRetentionJob::class, static function ($job) use ($user) {
            $job_user = TestCase::getObjectProperty($job, 'user');
            return $job_user->id === $user->id;
        });
        Queue::assertPushed(PasswordRetentionJob::class, static function ($job) use ($owner) {
            $job_user = TestCase::getObjectProperty($job, 'user');
            return $job_user->id === $owner->id;
        });

        // Test password_expiration_warning,
        // $owner was already warned today and $user 8 days ago
        Queue::fake();
        $owner->setSetting('password_expiration_warning', now()->toDateTimeString());
        $user->setSetting('password_expiration_warning', now()->copy()->subDays(8)->toDateTimeString());

        $code = \Artisan::call("password:retention");
        $this->assertSame(0, $code);

        Queue::assertPushed(PasswordRetentionJob::class, 1);
        Queue::assertPushed(PasswordRetentionJob::class, static function ($job) use ($user) {
            $job_user = TestCase::getObjectProperty($job, 'user');
            return $job_user->id === $user->id;
        });
    }
}
