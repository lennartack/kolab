<?php

namespace Tests\Unit\Mail;

use App\Mail\EmailVerification;
use App\User;
use App\Utils;
use App\VerificationCode;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    /**
     * Test email content
     */
    public function testBuild(): void
    {
        $code = new VerificationCode([
            'user_id' => 123456789,
            'mode' => VerificationCode::MODE_EMAIL,
            'code' => 'code',
            'short_code' => 'short-code',
        ]);

        // @phpstan-ignore-next-line
        $code->user = new User(['email' => 'test@user']);

        $mail = $this->renderMail(new EmailVerification($code));

        $html = $mail['html'];
        $plain = $mail['plain'];

        $url = Utils::serviceUrl('/code/' . $code->short_code . '-' . $code->code);
        $link = "<a href=\"{$url}\">{$url}</a>";
        $appName = \config('app.name');

        $this->assertSame("{$appName} Verification", $mail['subject']);

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertTrue(strpos($html, $code->user->name(true)) > 0);
        $this->assertStringContainsString($code->short_code, $html);
        $this->assertStringContainsString('This is the verification', $html);
        // $this->assertTrue(strpos($html, $link) > 0);

        $this->assertStringStartsWith("Dear " . $code->user->name(true), $plain);
        $this->assertStringContainsString($code->short_code, $plain);
        $this->assertStringContainsString('This is the verification', $plain);
        // $this->assertTrue(strpos($plain, $link) > 0);
    }

    /**
     * Test getSubject() and getUser()
     */
    public function testGetSubjectAndUser(): void
    {
        $appName = \config('app.name');
        $code = new VerificationCode([
            'user_id' => 123456789,
            'mode' => VerificationCode::MODE_EMAIL,
            'code' => 'code',
            'short_code' => 'short-code',
        ]);

        // @phpstan-ignore-next-line
        $code->user = new User();

        $mail = new EmailVerification($code);

        $this->assertSame("{$appName} Verification", $mail->getSubject());
        $this->assertSame($code->user, $mail->getUser());
    }
}
