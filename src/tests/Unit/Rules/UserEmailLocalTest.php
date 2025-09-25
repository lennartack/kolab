<?php

namespace Tests\Unit\Rules;

use App\Rules\UserEmailLocal;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UserEmailLocalTest extends TestCase
{
    /**
     * Test validation of email local part
     *
     * @dataProvider provideUserEmailLocalCases
     */
    public function testUserEmailLocal($user, $external, $error): void
    {
        $rules = ['user' => [new UserEmailLocal($external)]];

        $v = Validator::make(['user' => $user], $rules);

        if ($error) {
            $this->assertTrue($v->fails());
            $this->assertSame(['user' => [$error]], $v->errors()->toArray());
        } else {
            $this->assertFalse($v->fails());
        }
    }

    /**
     * List of email address validation cases for testUserEmailLocal()
     *
     * @return array Arguments for testUserEmailLocal()
     */
    public static function provideUserEmailLocalCases(): iterable
    {
        return [
            // non-string input
            [['test'], false, 'The specified user is invalid.'],
            // Invalid character
            ['test*test', false, 'The specified user is invalid.'],
            // Invalid syntax
            ['test.', false, 'The specified user is invalid.'],
            // Forbidden names
            ['Administrator', false, 'The specified user is not available.'],
            ['Admin', false, 'The specified user is not available.'],
            ['Sales', false, 'The specified user is not available.'],
            ['Root', false, 'The specified user is not available.'],
            ['Postmaster', false, 'The specified user is not available.'],
            ['Webmaster', false, 'The specified user is not available.'],
            ['anonymous', false, 'The specified user is not available.'],
            ['AnoNymous', false, 'The specified user is not available.'],
            ['anonymous', true, 'The specified user is not available.'],
            ['anyone', false, 'The specified user is not available.'],
            ['anyOne', true, 'The specified user is not available.'],
            // Valid
            ['test.test', false, null],
            // Valid for external domains
            ['Administrator', true, null],
            ['Admin', true, null],
            ['Sales', true, null],
            ['Root', true, null],
            ['Postmaster', true, null],
            ['Webmaster', true, null],
        ];
    }
}
