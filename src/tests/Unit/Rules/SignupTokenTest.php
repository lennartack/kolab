<?php

namespace Tests\Unit\Rules;

use App\Plan;
use App\Rules\SignupToken as SignupTokenRule;
use App\SignupToken;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class SignupTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Plan::where('title', 'test-plan')->delete();
        SignupToken::truncate();
    }

    protected function tearDown(): void
    {
        Plan::where('title', 'test-plan')->delete();
        SignupToken::truncate();

        parent::tearDown();
    }

    /**
     * Tests the signup token validator
     */
    public function testValidation(): void
    {
        $tokens = ['abcdefghijk', 'T-abcdefghijk'];

        $plan = Plan::where('title', 'individual')->first();
        $tokenPlan = Plan::create([
            'title' => 'test-plan',
            'description' => 'test',
            'name' => 'Test',
            'mode' => Plan::MODE_TOKEN,
        ]);

        SignupToken::create(['id' => $tokens[0], 'plans' => [$plan->id]]);
        SignupToken::create(['id' => $tokens[1], 'plans' => [$tokenPlan->id]]);

        $rules = ['token' => [new SignupTokenRule(null)]];

        // Empty input
        $v = Validator::make(['token' => null], $rules);
        $this->assertSame(['token' => ["The signup token is invalid."]], $v->errors()->toArray());

        // Length limit
        $v = Validator::make(['token' => str_repeat('a', 192)], $rules);
        $this->assertSame(['token' => ["The signup token is invalid."]], $v->errors()->toArray());

        // Valid token, but no plan
        $v = Validator::make(['token' => $tokens[1]], $rules);
        $this->assertSame(['token' => ["The signup token is invalid."]], $v->errors()->toArray());

        $rules = ['token' => [new SignupTokenRule($plan)]];

        // Plan that does not support tokens
        $v = Validator::make(['token' => $tokens[0]], $rules);
        $this->assertSame(['token' => ["The signup token is invalid."]], $v->errors()->toArray());

        $rules = ['token' => [new SignupTokenRule($tokenPlan)]];

        // Non-existing token
        $v = Validator::make(['token' => '123'], $rules);
        $this->assertSame(['token' => ["The signup token is invalid."]], $v->errors()->toArray());

        // Valid token
        $v = Validator::make(['token' => $tokens[1]], $rules);
        $this->assertSame([], $v->errors()->toArray());

        // Valid token (uppercase)
        $v = Validator::make(['token' => strtoupper($tokens[1])], $rules);
        $this->assertSame([], $v->errors()->toArray());
    }
}
