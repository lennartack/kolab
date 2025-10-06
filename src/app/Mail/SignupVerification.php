<?php

namespace App\Mail;

use App\SignupCode;
use App\Tenant;
use App\Utils;
use Illuminate\Support\Str;

class SignupVerification extends Mailable
{
    /** @var SignupCode A signup verification code object */
    protected $code;

    /**
     * Create a new message instance.
     *
     * @param SignupCode $code A signup verification code object
     */
    public function __construct(SignupCode $code)
    {
        $this->code = $code;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $appName = Tenant::getConfig($this->code->tenant_id, 'app.name');
        $href = Utils::serviceUrl(
            sprintf('/signup/%s-%s', $this->code->short_code, $this->code->code),
            $this->code->tenant_id
        );

        $vars = [
            'site' => $appName,
        ];

        $this->view('emails.html.signup_verification')
            ->text('emails.plain.signup_verification')
            ->subject(\trans('mail.signupverification-subject', $vars))
            ->with([
                'vars' => $vars,
                'href' => $href,
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
        $code = new SignupCode([
            'code' => Str::random(SignupCode::CODE_LENGTH),
            'short_code' => SignupCode::generateShortCode(),
            'email' => 'test@' . \config('app.domain'),
            'first_name' => 'Firstname',
            'last_name' => 'Lastname',
        ]);

        $mail = new self($code);

        return Helper::render($mail, $type);
    }
}
