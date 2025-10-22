<?php

namespace Tests\Unit\Rules;

use App\Rules\UserEmailDomain;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UserEmailDomainTest extends TestCase
{
    /**
     * Test validation of email domain
     */
    public function testUserEmailDomain(): void
    {
        $rules = ['domain' => [new UserEmailDomain()]];

        // Non-string input
        $v = Validator::make(['domain' => ['domain.tld']], $rules);

        $this->assertTrue($v->fails());
        $this->assertSame(['domain' => ['The specified domain is invalid.']], $v->errors()->toArray());

        // Non-fqdn name
        $v = Validator::make(['domain' => 'local'], $rules);

        $this->assertTrue($v->fails());
        $this->assertSame(['domain' => ['The specified domain is invalid.']], $v->errors()->toArray());

        // www. prefix not allowed
        $v = Validator::make(['domain' => 'www.local.tld'], $rules);

        $this->assertTrue($v->fails());
        $this->assertSame(['domain' => ['The specified domain is invalid.']], $v->errors()->toArray());

        // invalid domain
        $v = Validator::make(['domain' => 'local..tld'], $rules);

        $this->assertTrue($v->fails());
        $this->assertSame(['domain' => ['The specified domain is invalid.']], $v->errors()->toArray());

        // invalid domain (utf8)
        // For now IDN domains aren't allowed because it seems ldap_add() fails (and who knows what else)
        $v = Validator::make(['domain' => 'tİktok.com'], $rules);

        $this->assertTrue($v->fails());
        $this->assertSame(['domain' => ['The specified domain is invalid.']], $v->errors()->toArray());

        // Valid domain
        $domain = str_repeat('abcdefghi.', 18) . 'abcdefgh.pl'; // 191 chars
        $v = Validator::make(['domain' => $domain], $rules);

        $this->assertFalse($v->fails());

        // Domain too long
        $domain = str_repeat('abcdefghi.', 18) . 'abcdefghi.pl'; // too long domain, 192 chars
        $v = Validator::make(['domain' => $domain], $rules);

        $this->assertTrue($v->fails());
        $this->assertSame(['domain' => ['The specified domain is invalid.']], $v->errors()->toArray());

        $rules = ['domain' => [new UserEmailDomain(['kolabnow.com'])]];

        // Domain not belongs to a set of allowed domains
        $v = Validator::make(['domain' => 'domain.tld'], $rules);

        $this->assertTrue($v->fails());
        $this->assertSame(['domain' => ['The specified domain is invalid.']], $v->errors()->toArray());

        // Domain on the allowed domains list
        $v = Validator::make(['domain' => 'kolabNow.com'], $rules);

        $this->assertFalse($v->fails());
    }
}
