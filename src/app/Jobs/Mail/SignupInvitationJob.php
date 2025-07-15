<?php

namespace App\Jobs\Mail;

use App\Jobs\MailJob;
use App\Mail\Helper;
use App\Mail\SignupInvitation as SignupInvitationMail;
use App\SignupInvitation;

class SignupInvitationJob extends MailJob
{
    /** @var SignupInvitation Signup invitation object */
    protected $invitation;

    /**
     * Create a new job instance.
     *
     * @param SignupInvitation $invitation Invitation object
     */
    public function __construct(SignupInvitation $invitation)
    {
        $this->invitation = $invitation;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        Helper::sendMail(
            new SignupInvitationMail($this->invitation),
            $this->invitation->tenant_id,
            ['to' => $this->invitation->email]
        );

        // Update invitation status
        $this->invitation->status = SignupInvitation::STATUS_SENT;
        $this->invitation->saveQuietly();
    }

    /**
     * The job failed to process.
     */
    public function failed(\Exception $exception)
    {
        if ($this->attempts() >= $this->tries) {
            // Update invitation status
            $this->invitation->status = SignupInvitation::STATUS_FAILED;
            $this->invitation->saveQuietly();
        }
    }
}
