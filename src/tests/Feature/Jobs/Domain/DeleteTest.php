<?php

namespace Tests\Feature\Jobs\Domain;

use App\Domain;
use App\Jobs\Domain\DeleteJob;
use App\Support\Facades\LDAP;
use Tests\TestCase;

class DeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestDomain('domain-create-test.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestDomain('domain-create-test.com');

        parent::tearDown();
    }

    /**
     * Test job handle
     */
    public function testHandle(): void
    {
        $domain = $this->getTestDomain(
            'domain-create-test.com',
            [
                'status' => Domain::STATUS_NEW | Domain::STATUS_LDAP_READY,
                'type' => Domain::TYPE_EXTERNAL,
            ]
        );

        // Test job failure (domain not trashed)
        $job = (new DeleteJob($domain->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertFailedWith("Domain {$domain->namespace} is not deleted.");

        // Test job success
        $domain->deleted_at = \now();
        $domain->saveQuietly();

        $this->assertTrue($domain->isLdapReady());
        $this->assertTrue($domain->trashed());
        $this->assertFalse($domain->isDeleted());

        \config(['app.with_ldap' => true]);
        LDAP::shouldReceive('deleteDomain')->once()->with($domain);

        $job = (new DeleteJob($domain->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();

        $domain->refresh();
        $this->assertFalse($domain->isLdapReady());
        $this->assertTrue($domain->isDeleted());

        // Test domain marked as deleted
        $job = (new DeleteJob($domain->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertNotFailed();
    }
}
