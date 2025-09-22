<?php

namespace Tests\Feature\Console\Data\Import;

use App\Plan;
use App\SignupToken;
use Tests\TestCase;

class SignupTokensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Plan::where('title', 'test1')->delete();
        Plan::where('title', 'test2')->delete();
        SignupToken::truncate();
    }

    protected function tearDown(): void
    {
        Plan::where('title', 'test1')->delete();
        Plan::where('title', 'test2')->delete();
        SignupToken::truncate();

        @unlink(storage_path('test-tokens.txt'));

        parent::tearDown();
    }

    /**
     * Test the command
     */
    public function testHandle(): void
    {
        $file = storage_path('test-tokens.txt');
        file_put_contents($file, '');

        // Unknown plan
        $code = \Artisan::call("data:import:signup-tokens {$file} unknown");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame("Plan 'unknown' not found", $output);

        // Plan not for tokens
        $code = \Artisan::call("data:import:signup-tokens {$file} individual");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame("Plan 'individual' is not for tokens", $output);

        $plan1 = Plan::create([
            'title' => 'test1',
            'name' => 'Test Account 1',
            'description' => 'Test',
            'mode' => Plan::MODE_TOKEN,
        ]);

        $plan2 = Plan::create([
            'title' => 'test2',
            'name' => 'Test Account 2',
            'description' => 'Test',
            'mode' => Plan::MODE_TOKEN,
        ]);

        // Non-existent input file
        $code = \Artisan::call("data:import:signup-tokens nofile.txt {$plan1->title}");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame("File 'nofile.txt' does not exist", $output);

        // Empty input file
        $code = \Artisan::call("data:import:signup-tokens {$file} {$plan1->title}");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame("File '{$file}' is empty", $output);

        // Valid tokens
        file_put_contents($file, "12345\r\nabcde");
        $code = \Artisan::call("data:import:signup-tokens {$file} {$plan1->id} {$plan2->title}");
        $output = trim(\Artisan::output());

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Validating tokens... DONE", $output);
        $this->assertStringContainsString("Importing tokens... DONE", $output);
        $tokens = SignupToken::orderBy('id')->get();
        $this->assertCount(2, $tokens);
        $this->assertSame('12345', $tokens[0]->id);
        $this->assertSame([$plan1->id, $plan2->id], $tokens[0]->plans);
        $this->assertSame('ABCDE', $tokens[1]->id);
        $this->assertSame([$plan1->id, $plan2->id], $tokens[1]->plans);

        // Attempt the same tokens again
        $code = \Artisan::call("data:import:signup-tokens {$file} {$plan1->id}");
        $output = trim(\Artisan::output());

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Validating tokens... DONE", $output);
        $this->assertStringContainsString("Nothing to import", $output);
        $this->assertStringNotContainsString("Importing tokens...", $output);
    }
}
