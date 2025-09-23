<?php

namespace Tests\Unit;

use App\VerificationCode;
use Tests\TestCase;

class VerificationCodeTest extends TestCase
{
    /**
     * Test VerificationCode::generateShortCode()
     */
    public function testGenerateShortCode()
    {
        $code = VerificationCode::generateShortCode();

        $code_length = env('VERIFICATION_CODE_LENGTH', VerificationCode::SHORTCODE_LENGTH);

        $this->assertTrue(strlen($code) === $code_length);
        $this->assertTrue(strspn($code, '1234567890') === strlen($code));
    }
}
