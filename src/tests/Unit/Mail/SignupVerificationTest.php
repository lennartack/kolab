<?php

namespace Tests\Unit\Mail;

use App\Mail\SignupVerification;
use App\SignupCode;
use App\Utils;
use Tests\TestCase;

class SignupVerificationTest extends TestCase
{
    /**
     * Test email content
     */
    public function testBuild(): void
    {
        $code = new SignupCode([
            'code' => 'code',
            'short_code' => 'short-code',
            'email' => 'test@email',
            'first_name' => 'First',
            'last_name' => 'Last',
        ]);

        $mail = $this->renderMail(new SignupVerification($code));

        $html = $mail['html'];
        $plain = $mail['plain'];

        $url = Utils::serviceUrl('/signup/' . $code->short_code . '-' . $code->code);
        $link = "<a href=\"{$url}\">{$url}</a>";
        $appName = \config('app.name');

        $this->assertSame("{$appName} Registration", $mail['subject']);

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertTrue(strpos($html, $link) > 0);
        $this->assertStringContainsString('Hi', $html);

        $this->assertStringStartsWith('Hi,', $plain);
        $this->assertTrue(strpos($plain, $url) > 0);
    }

    /**
     * Test getSubject() and getUser()
     */
    public function testGetSubjectAndUser(): void
    {
        $appName = \config('app.name');
        $code = new SignupCode([
            'code' => 'code',
            'short_code' => 'short-code',
            'email' => 'test@email',
            'first_name' => 'First',
            'last_name' => 'Last',
        ]);

        $mail = new SignupVerification($code);

        $this->assertSame("{$appName} Registration", $mail->getSubject());
        $this->assertNull($mail->getUser());
    }
}
