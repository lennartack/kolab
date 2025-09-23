<?php

namespace App\Jobs\Mail;

use App\Jobs\MailJob;
use App\Mail\EmailVerification;
use App\Mail\Helper;
use App\VerificationCode;

class EmailVerificationJob extends MailJob
{
    /** @var string Verification code identifier */
    protected $code;

    /**
     * Create a new job instance.
     *
     * @param string $code Verification code identifier
     */
    public function __construct(string $code)
    {
        $this->code = $code;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $code = VerificationCode::find($this->code);

        if (empty($code) || !$code->active || $code->isExpired()) {
            // Code does not exist or got deactivated, nothing to do here
            return;
        }

        $settings = $code->user->getSettings(['external_email_new', 'external_email_code']);

        if (empty($settings['external_email_new']) || $settings['external_email_code'] != $code->code) {
            // Settings changed in meantime, nothing to do here
            return;
        }

        Helper::sendMail(
            new EmailVerification($code),
            $code->user->tenant_id,
            ['to' => $settings['external_email_new']]
        );
    }
}
