<?php

namespace Tests\Feature\Policy;

use App\Delegation;
use App\Policy\SmtpAccess;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SmtpAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Delegation::query()->delete();
        $john = $this->getTestUser('john@kolab.org');
        $john->status &= ~User::STATUS_SUSPENDED;
        $john->save();
        $jack = $this->getTestUser('jack@kolab.org');
        $jack->status &= ~User::STATUS_SUSPENDED;
        $jack->save();
    }

    protected function tearDown(): void
    {
        $this->deleteTestGroup('group-test@kolab.org');
        Delegation::query()->delete();
        $john = $this->getTestUser('john@kolab.org');
        $john->status &= ~User::STATUS_SUSPENDED;
        $john->save();
        $jack = $this->getTestUser('jack@kolab.org');
        $jack->status &= ~User::STATUS_SUSPENDED;
        $jack->save();

        parent::tearDown();
    }

    /**
     * Test verifyRecipient() method
     */
    public function testVerifyRecipient(): void
    {
        $group = $this->getTestGroup('group-test@kolab.org');

        // invalid sender address
        $this->assertFalse(SmtpAccess::verifyRecipient('invalid', 'none@unknown.tld'));

        // non-existing recipient
        $this->assertTrue(SmtpAccess::verifyRecipient('ext@gmail.com', 'none@unknown.tld'));

        // no policy for a group
        $this->assertTrue(SmtpAccess::verifyRecipient('ext@gmail.com', $group->email));

        $group->setConfig(['sender_policy' => ['.gmail.com', 'allowed.tld', 'allowed@kolab.org']]);

        // domain suffix match
        $this->assertTrue(SmtpAccess::verifyRecipient('ext@test.gmail.com', $group->email));

        // domain match
        $this->assertTrue(SmtpAccess::verifyRecipient('ext@allowed.tld', $group->email));

        // email address match
        $this->assertTrue(SmtpAccess::verifyRecipient('allowed@kolab.org', $group->email));

        // no match
        $this->assertFalse(SmtpAccess::verifyRecipient('test@kolab.ch', $group->email));
    }

    /**
     * Test verifySender() method
     */
    public function testVerifySender(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $noreply = User::where('email', \config('mail.mailers.smtp.username'))->first();

        // Test main email address
        $this->assertTrue(SmtpAccess::verifySender($john, ucfirst($john->email)));

        // Test noreply@ user
        if ($noreply) {
            $this->assertTrue(SmtpAccess::verifySender($noreply, $john->email));
        }

        // Test an alias
        $this->assertTrue(SmtpAccess::verifySender($john, 'John.Doe@kolab.org'));

        // Test another user's email address
        $this->assertFalse(SmtpAccess::verifySender($jack, $john->email));

        // Test another user's alias
        $this->assertFalse(SmtpAccess::verifySender($jack, 'john.doe@kolab.org'));

        Queue::fake();
        Delegation::create(['user_id' => $john->id, 'delegatee_id' => $jack->id]);

        // Test delegator's email address
        $this->assertTrue(SmtpAccess::verifySender($jack, $john->email));

        // Test delegator's alias
        $this->assertTrue(SmtpAccess::verifySender($jack, 'john.doe@kolab.org'));

        // Test delegator's alias, but suspended delegator
        $john->suspend();
        $this->assertFalse(SmtpAccess::verifySender($jack, 'john.doe@kolab.org'));

        // Test invalid/unknown email
        $this->assertFalse(SmtpAccess::verifySender($jack, 'unknown'));
        $this->assertFalse(SmtpAccess::verifySender($jack, 'unknown@domain.tld'));

        // Test suspended user
        $jack->suspend();
        $this->assertFalse(SmtpAccess::verifySender($jack, $jack->email));
    }
}
