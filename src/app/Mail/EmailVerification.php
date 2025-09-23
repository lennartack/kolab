<?php

namespace App\Mail;

use App\Tenant;
use App\Utils;
use App\VerificationCode;
use Illuminate\Support\Str;

class EmailVerification extends Mailable
{
    /** @var VerificationCode A verification code object */
    protected $code;

    /**
     * Create a new message instance.
     *
     * @param VerificationCode $code A verification code object
     */
    public function __construct(VerificationCode $code)
    {
        $this->code = $code;
        $this->user = $code->user;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $appName = Tenant::getConfig($this->code->user->tenant_id, 'app.name');
        /*
        // TODO: Include clickable link if this is a user with no role=device?
        $href = Utils::serviceUrl(
            sprintf('/code/%s-%s', $this->code->short_code, $this->code->code),
            $this->code->user->tenant_id
        );
        */

        $vars = [
            'site' => $appName,
            'name' => $this->code->user->name(true),
        ];

        $this->view('emails.html.email_verification')
            ->text('emails.plain.email_verification')
            ->subject(\trans('mail.emailverification-subject', $vars))
            ->with([
                'vars' => $vars,
                // 'href' => $href,
                'code' => $this->code->code,
                'short_code' => $this->code->short_code,
            ]);

        return $this;
    }

    /**
     * Render the mail template with fake data
     *
     * @param string $type Output format ('html' or 'text')
     *
     * @return string HTML or Plain Text output
     */
    public static function fakeRender(string $type = 'html'): string
    {
        $code = new VerificationCode([
            'code' => Str::random(VerificationCode::CODE_LENGTH),
            'short_code' => VerificationCode::generateShortCode(),
            'mode' => VerificationCode::MODE_EMAIL,
        ]);

        // @phpstan-ignore-next-line
        $code->user = new User();
        $code->user->email = 'test@' . \config('app.domain');

        $mail = new self($code);

        return Helper::render($mail, $type);
    }
}
