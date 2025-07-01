<?php

namespace Tests\Feature\Console\Scalpel\Domain;

use App\Domain;
use Tests\TestCase;

class ReadCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestDomain('domain-delete.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestDomain('domain-delete.com');

        parent::tearDown();
    }

    /**
     * Test the command execution
     */
    public function testHandle(): void
    {
        // Test --help argument
        $code = \Artisan::call("scalpel:domain:read --help");
        $output = trim(\Artisan::output());

        $this->assertSame(0, $code);
        $this->assertStringContainsString('--attr[=ATTR]', $output);
        $this->assertStringContainsString('--with-deleted', $output);

        // Test unknown domain
        $code = \Artisan::call("scalpel:domain:read unknown-domain.tld --with-deleted");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame('No such domain unknown-domain.tld', $output);

        // Test existing domain
        $domain = $this->getTestDomain('domain-delete.com', ['type' => Domain::TYPE_EXTERNAL]);

        $code = \Artisan::call("scalpel:domain:read {$domain->namespace} --attr=namespace");
        $output = trim(\Artisan::output());

        $this->assertSame(0, $code);
        $this->assertSame("{$domain->id} {$domain->namespace}", $output);

        // Test deleted domain
        $domain->delete();
        $code = \Artisan::call("scalpel:domain:read {$domain->namespace} --attr=namespace");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame("No such domain {$domain->namespace}", $output);

        $code = \Artisan::call("scalpel:domain:read {$domain->namespace} --attr=namespace --with-deleted");
        $output = trim(\Artisan::output());

        $this->assertSame(0, $code);
        $this->assertSame("{$domain->id} {$domain->namespace}", $output);
    }
}
