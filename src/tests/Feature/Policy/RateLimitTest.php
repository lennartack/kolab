<?php

namespace Tests\Feature\Policy;

use App\Policy\RateLimit;
use App\Policy\Response;
use App\User;
use Tests\TestCase;

/**
 * @group data
 */
class RateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTest();

        RateLimit::query()->delete();

        // Set some low limits for tests
        \config([
            'policy.ratelimit.whitelist' => [],
            'policy.ratelimit.max_recipients' => 10,
            'policy.ratelimit.max_recipients_restricted' => 5,
            'policy.ratelimit.suspend_max_recipients' => 20,
            'policy.ratelimit.suspend_max_recipients_restricted' => 10,
            'policy.ratelimit.max_recipients_daily' => 20,
            'policy.ratelimit.max_recipients_restricted_daily' => 10,
            'policy.ratelimit.suspend_max_recipients_daily' => 30,
            'policy.ratelimit.suspend_max_recipients_restricted_daily' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        RateLimit::query()->delete();

        parent::tearDown();
    }

    /**
     * Test verifyRequest() method for an individual account
     */
    public function testVerifyRequestIndividualAccount()
    {
        // Verify an individual can send an email unrestricted
        // first 9 requests
        for ($i = 1; $i <= 9; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(200, $result->code);
            $this->assertSame(Response::ACTION_DUNNO, $result->action);
            $this->assertSame('', $result->reason);
        }

        // requests 10 through 19 get DEFERed
        for ($i = 10; $i <= 19; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(403, $result->code);
            $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
            $this->assertSame('The account is at 10 recipients per hour, cool down.', $result->reason);
        }

        // not suspended yet
        $this->publicDomainUser->refresh();
        $this->assertFalse($this->publicDomainUser->isSuspended());

        // Test that message to the same recipient is not being counted as new
        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0019@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
        $this->assertSame('The account is at 10 recipients per hour, cool down.', $result->reason);

        // not suspended yet
        $this->publicDomainUser->refresh();
        $this->assertFalse($this->publicDomainUser->isSuspended());

        // request #20 (with a new recipient) should suspend
        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0020@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
        $this->assertSame('The account is at 10 recipients per hour, cool down.', $result->reason);

        $this->publicDomainUser->refresh();
        $this->assertTrue($this->publicDomainUser->isSuspended());

        // next request is on HOLD
        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0030@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_HOLD, $result->action);
        $this->assertSame('Sender deleted or suspended', $result->reason);

        $this->publicDomainUser->unsuspend();

        // Test whitelisted user
        $whitelist = RateLimit\Whitelist::create([
            'whitelistable_id' => $this->publicDomainUser->id,
            'whitelistable_type' => User::class,
        ]);

        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0040@test.domain']);
        $this->assertSame(200, $result->code);
        $this->assertSame(Response::ACTION_DUNNO, $result->action);
        $this->assertSame('', $result->reason);

        // Test whitelisted but suspended user
        $this->publicDomainUser->suspend();

        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0050@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_HOLD, $result->action);
        $this->assertSame('Sender deleted or suspended', $result->reason);

        // Test deleted user
        $this->publicDomainUser->unsuspend();
        $this->publicDomainUser->delete();

        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0060@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_HOLD, $result->action);
        $this->assertSame('Sender deleted or suspended', $result->reason);
    }

    /**
     * Test verifyRequest() method for an individual account regarding daily limits
     */
    public function testVerifyRequestIndividualAccountDailyLimits()
    {
        // Create first 15 requests
        for ($i = 1; $i <= 15; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
        }

        // and move them 2h back
        RateLimit::query()->update(['updated_at' => now()->subHours(3)]);

        // next 4 messages should be unlimited again
        for ($i = 16; $i <= 19; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(200, $result->code);
            $this->assertSame(Response::ACTION_DUNNO, $result->action);
            $this->assertSame('', $result->reason);
        }

        // next 5 should not suspend the user yet, but block mail delivery
        for ($i = 20; $i <= 24; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(403, $result->code);
            $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
            $this->assertSame('The account is at 20 recipients per day, cool down.', $result->reason);
        }

        // not suspended yet
        $this->publicDomainUser->refresh();
        $this->assertFalse($this->publicDomainUser->isSuspended());

        RateLimit::query()->where('updated_at', '>=', now()->subHour())->update(['updated_at' => now()->subHours(2)]);

        // next hour
        for ($i = 25; $i <= 29; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(403, $result->code);
            $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
            $this->assertSame('The account is at 20 recipients per day, cool down.', $result->reason);
        }

        // not suspended yet
        $this->publicDomainUser->refresh();
        $this->assertFalse($this->publicDomainUser->isSuspended());

        // message #30, auto-suspend limit reached
        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0030@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
        $this->assertSame('The account is at 20 recipients per day, cool down.', $result->reason);

        $this->publicDomainUser->refresh();
        $this->assertTrue($this->publicDomainUser->isSuspended());
    }

    /**
     * Test verifyRequest() method for a restricted account
     */
    public function testVerifyRequestRestrictedAccount()
    {
        $this->publicDomainUser->status |= User::STATUS_RESTRICTED;
        $this->publicDomainUser->save();

        // Verify an individual can send an email unrestricted
        // first 4 requests
        for ($i = 1; $i <= 4; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(200, $result->code);
            $this->assertSame(Response::ACTION_DUNNO, $result->action);
            $this->assertSame('', $result->reason);
        }

        // requests 5 through 10 get DEFERed
        for ($i = 5; $i <= 9; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(403, $result->code);
            $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
            $this->assertSame('The account is at 5 recipients per hour, cool down.', $result->reason);
        }

        // not suspended yet
        $this->publicDomainUser->refresh();
        $this->assertFalse($this->publicDomainUser->isSuspended());

        // request #10 should suspend
        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0020@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
        $this->assertSame('The account is at 5 recipients per hour, cool down.', $result->reason);

        $this->publicDomainUser->refresh();
        $this->assertTrue($this->publicDomainUser->isSuspended());

        // next request is on HOLD
        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0030@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_HOLD, $result->action);
        $this->assertSame('Sender deleted or suspended', $result->reason);
    }

    /**
     * Test verifyRequest() method for a restricted account regarding daily limits
     */
    public function testVerifyRequestRestrictedAccountDailyLimits()
    {
        $this->publicDomainUser->status |= User::STATUS_RESTRICTED;
        $this->publicDomainUser->save();

        // Create first 8 requests
        for ($i = 1; $i <= 8; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
        }

        // and move them 2h back
        RateLimit::query()->update(['updated_at' => now()->subHours(4)]);

        // new hour, next request should be unlimited
        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0009@test.domain']);
        $this->assertSame(200, $result->code);
        $this->assertSame(Response::ACTION_DUNNO, $result->action);
        $this->assertSame('', $result->reason);

        // next 3 messages should reach daily limit
        for ($i = 10; $i <= 12; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(403, $result->code);
            $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
            $this->assertSame('The account is at 10 recipients per day, cool down.', $result->reason);
        }

        RateLimit::query()->where('updated_at', '>=', now()->subHour())->update(['updated_at' => now()->subHours(3)]);

        // new hour, 4 requests pass unlimited
        for ($i = 13; $i <= 16; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(403, $result->code);
            $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
            $this->assertSame('The account is at 10 recipients per day, cool down.', $result->reason);
        }

        RateLimit::query()->where('updated_at', '>=', now()->subHour())->update(['updated_at' => now()->subHours(2)]);

        // new hour, 3 requests pass unlimited
        for ($i = 17; $i <= 19; $i++) {
            $result = RateLimit::verifyRequest($this->publicDomainUser, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(403, $result->code);
            $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
            $this->assertSame('The account is at 10 recipients per day, cool down.', $result->reason);
        }

        // not suspended yet
        $this->publicDomainUser->refresh();
        $this->assertFalse($this->publicDomainUser->isSuspended());

        // request #20 should suspend the user
        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0020@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
        $this->assertSame('The account is at 10 recipients per day, cool down.', $result->reason);

        $this->publicDomainUser->refresh();
        $this->assertTrue($this->publicDomainUser->isSuspended());
    }

    /**
     * Test verifyRequest() with group account cases
     */
    public function testVerifyRequestGroupAccount()
    {
        // send some mail as one user
        for ($i = 1; $i <= 9; $i++) {
            $result = RateLimit::verifyRequest($this->domainOwner, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(200, $result->code);
            $this->assertSame(Response::ACTION_DUNNO, $result->action);
            $this->assertSame('', $result->reason);
        }

        // the tenth request should be blocked even if done by another user in that account
        for ($i = 10; $i <= 19; $i++) {
            $result = RateLimit::verifyRequest($this->jack, [sprintf("%04d@test.domain", $i)]);
            $this->assertSame(403, $result->code);
            $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
            $this->assertSame('The account is at 10 recipients per hour, cool down.', $result->reason);
        }

        $this->domainOwner->refresh();
        $this->assertFalse($this->domainOwner->isSuspended());

        // Finally another user can suspend the whole account
        $result = RateLimit::verifyRequest($this->joe, ['0202@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
        $this->assertSame('The account is at 10 recipients per hour, cool down.', $result->reason);

        $this->domainOwner->refresh();
        $this->assertTrue($this->domainOwner->isSuspended());
        $this->joe->refresh();
        $this->assertTrue($this->joe->isSuspended());
        $this->jack->refresh();
        $this->assertTrue($this->jack->isSuspended());
    }

    /**
     * Test verifyRequest() method for an individual account
     */
    public function testVerifyMultiRecipientRequestLarge()
    {
        # Immediately block an email that exceeds the limit
        $recipients = [];
        for ($i = 1; $i <= 19; $i++) {
            $recipients[] = sprintf("%04d@test.domain", $i);
        }
        $result = RateLimit::verifyRequest($this->publicDomainUser, $recipients);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
        $this->assertSame('The account is at 10 recipients per hour, cool down.', $result->reason);

        // We remember the limit
        $result = RateLimit::verifyRequest($this->publicDomainUser, ['0202@test.domain']);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
        $this->assertSame('The account is at 10 recipients per hour, cool down.', $result->reason);
    }

    /**
     * Test verifyRequest() method for an individual account
     */
    public function testVerifyMultiRecipientRequest()
    {
        // Verify an individual can send an email unrestricted
        // first 9 requests
        $recipients = [];
        for ($i = 1; $i <= 9; $i++) {
            $recipients[] = sprintf("%04d@test.domain", $i);
        }
        $result = RateLimit::verifyRequest($this->publicDomainUser, $recipients);
        $this->assertSame(200, $result->code);
        $this->assertSame(Response::ACTION_DUNNO, $result->action);
        $this->assertSame('', $result->reason);

        // requests 10 through 19 get DEFERed
        $recipients2 = [];
        for ($i = 10; $i <= 19; $i++) {
            $recipients2[] = sprintf("%04d@test.domain", $i);
        }
        $result = RateLimit::verifyRequest($this->publicDomainUser, $recipients2);
        $this->assertSame(403, $result->code);
        $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
        $this->assertSame('The account is at 10 recipients per hour, cool down.', $result->reason);

        // FIXME
        // $result = RateLimit::verifyRequest($this->publicDomainUser, $recipients);
        // $this->assertSame(200, $result->code);
        // $this->assertSame(Response::ACTION_DEFER_IF_PERMIT, $result->action);
        // $this->assertSame('', $result->reason);
        // $this->assertSame('The account is at 10 recipients per hour, cool down.', $result->reason);
    }
}
