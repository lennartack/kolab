<?php

namespace Tests\Unit\Rules;

use App\Rules\SharedFolderName;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SharedFolderNameTest extends TestCase
{
    /**
     * Tests the shared folder name validator
     */
    public function testValidation(): void
    {
        $user = $this->getTestUser('john@kolab.org');
        $rules = ['name' => ['present', new SharedFolderName($user, 'kolab.org')]];

        // Empty/invalid input
        $v = Validator::make(['name' => null], $rules);
        $this->assertSame(['name' => ["The specified name is invalid."]], $v->errors()->toArray());

        $v = Validator::make(['name' => []], $rules);
        $this->assertSame(['name' => ["The specified name is invalid."]], $v->errors()->toArray());

        $v = Validator::make(['name' => ['Resources']], $rules);
        $this->assertSame(['name' => ["The specified name is invalid."]], $v->errors()->toArray());

        $v = Validator::make(['name' => ['Resources/Test']], $rules);
        $this->assertSame(['name' => ["The specified name is invalid."]], $v->errors()->toArray());

        // Forbidden chars
        $v = Validator::make(['name' => 'Test@'], $rules);
        $this->assertSame(['name' => ["The specified name is invalid."]], $v->errors()->toArray());

        // Length limit
        $v = Validator::make(['name' => str_repeat('a', 192)], $rules);
        $this->assertSame(['name' => ["The name may not be greater than 191 characters."]], $v->errors()->toArray());

        // Existing resource
        $v = Validator::make(['name' => 'Library'], $rules);
        $this->assertSame(['name' => ["The specified name is not available."]], $v->errors()->toArray());

        // Valid name
        $v = Validator::make(['name' => 'TestRule'], $rules);
        $this->assertSame([], $v->errors()->toArray());

        // Invalid domain
        $rules = ['name' => ['present', new SharedFolderName($user, 'kolabnow.com')]];
        $v = Validator::make(['name' => 'TestRule'], $rules);
        $this->assertSame(['name' => ["The specified domain is invalid."]], $v->errors()->toArray());

        // Invalid subfolders
        $rules = ['name' => ['present', new SharedFolderName($user, 'kolab.org')]];
        $v = Validator::make(['name' => 'Test//Rule'], $rules);
        $this->assertSame(['name' => ["The specified name is invalid."]], $v->errors()->toArray());
        $v = Validator::make(['name' => '/TestRule'], $rules);
        $this->assertSame(['name' => ["The specified name is invalid."]], $v->errors()->toArray());
        $v = Validator::make(['name' => 'TestRule/'], $rules);
        $this->assertSame(['name' => ["The specified name is invalid."]], $v->errors()->toArray());

        // Valid subfolder
        $v = Validator::make(['name' => 'Test/Rule/Folder'], $rules);
        $this->assertSame([], $v->errors()->toArray());
    }
}
