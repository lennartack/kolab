<?php

namespace Tests\Feature;

use App\VerificationCode;
use Carbon\Carbon;
use Tests\TestCase;

class VerificationCodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('UserAccountA@UserAccount.com');
        Carbon::setTestNow(Carbon::create(2021, 5, 5, 12));
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('UserAccountA@UserAccount.com');

        parent::tearDown();
        Carbon::setTestNow();
    }

    /**
     * Test VerificationCode creation
     */
    public function testVerificationCodeCreate(): void
    {
        $user = $this->getTestUser('UserAccountA@UserAccount.com');
        $data = [
            'user_id' => $user->id,
            'mode' => 'password-reset',
        ];

        $code = VerificationCode::create($data);

        $code_length = env('VERIFICATION_CODE_LENGTH', VerificationCode::SHORTCODE_LENGTH);
        $code_exp_hrs = env('VERIFICATION_CODE_EXPIRY', VerificationCode::CODE_EXP_HOURS);
        $exp = Carbon::now()->addHours($code_exp_hrs);

        $this->assertFalse($code->isExpired());
        $this->assertTrue(strlen($code->code) === VerificationCode::CODE_LENGTH);
        $this->assertTrue(strlen($code->short_code) === $code_length);
        $this->assertTrue($code->active);
        $this->assertSame($data['mode'], $code->mode);
        $this->assertSame($user->id, $code->user->id);
        $this->assertInstanceOf(\DateTime::class, $code->expires_at);
        $this->assertSame($code->expires_at->toDateTimeString(), $exp->toDateTimeString());

        $inst = VerificationCode::find($code->code);

        $this->assertInstanceOf(VerificationCode::class, $inst);
        $this->assertSame($inst->code, $code->code);

        // Custom active flag and custom expires_at
        $data['expires_at'] = Carbon::now()->addDays(10);
        $data['active'] = false;
        $code = VerificationCode::create($data);
        $this->assertFalse($code->active);
        $this->assertSame($code->expires_at->toDateTimeString(), $data['expires_at']->toDateTimeString());
    }
}
