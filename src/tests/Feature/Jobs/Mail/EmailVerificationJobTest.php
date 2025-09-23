<?php

namespace Tests\Feature\Jobs\Mail;

use App\Jobs\Mail\EmailVerificationJob;
use App\Mail\EmailVerification;
use App\VerificationCode;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailVerificationJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('EmailVerification@UserAccount.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('EmailVerification@UserAccount.com');

        parent::tearDown();
    }

    /**
     * Test job handle
     */
    public function testHandle(): void
    {
        $code = new VerificationCode(['mode' => VerificationCode::MODE_EMAIL]);

        $user = $this->getTestUser('EmailVerification@UserAccount.com');
        $user->verificationCodes()->save($code);
        $user->setSettings(['external_email_new' => 'etx@email.com', 'external_email_code' => $code->code]);

        Mail::fake();

        // Assert that no jobs were pushed...
        Mail::assertNothingSent();

        $job = new EmailVerificationJob($code->code);
        $job->handle();

        // Assert the email sending job was pushed once
        Mail::assertSent(EmailVerification::class, 1);

        Mail::assertSent(EmailVerification::class, static function ($mail) {
            // Assert the mail was sent to the code's email
            return $mail->hasTo('etx@email.com')
                // Assert sender
                && $mail->hasFrom(\config('mail.sender.address'), \config('mail.sender.name'))
                && $mail->hasReplyTo(\config('mail.replyto.address'), \config('mail.replyto.name'));
        });

        // Test that the job is dispatched to the proper queue
        Queue::fake();
        EmailVerificationJob::dispatch($code);
        Queue::assertPushedOn(\App\Enums\Queue::Mail->value, EmailVerificationJob::class);
    }
}
