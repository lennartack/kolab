<?php

namespace Tests\Feature\Policy;

use App\Domain;
use App\Policy\Utils;
use Tests\TestCase;

class UtilsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('UserAccountA@UserAccount.com');
        $this->deleteTestDomain('UserAccount.com');
        $this->deleteTestSharedFolder('folder-test@kolabnow.com');
        $this->deleteTestResource('resource-test@kolabnow.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('UserAccountA@UserAccount.com');
        $this->deleteTestDomain('UserAccount.com');
        $this->deleteTestSharedFolder('folder-test@kolabnow.com');
        $this->deleteTestResource('resource-test@kolabnow.com');

        parent::tearDown();
    }

    /**
     * Test Utils::findObjectsByRecipientAddress()
     */
    public function testFindObjectsByRecipientAddress(): void
    {
        $result = Utils::findObjectsByRecipientAddress('unknown');
        $this->assertCount(0, $result);

        $result = Utils::findObjectsByRecipientAddress('unknown@unknow.org');
        $this->assertCount(0, $result);

        $result = Utils::findObjectsByRecipientAddress('unknown@kolab.org');
        $this->assertCount(0, $result);

        // Users

        $user = $this->getTestUser('UserAccountA@UserAccount.com');
        $domain = $this->getTestDomain('UserAccount.com', [
            'status' => Domain::STATUS_NEW | Domain::STATUS_ACTIVE,
            'type' => Domain::TYPE_PUBLIC,
        ]);

        $result = Utils::findObjectsByRecipientAddress('UserAccountA@UserAccount.com');

        $this->assertCount(1, $result);
        $this->assertSame($user->email, $result[0]->email);

        $user->setAliases(['test@UserAccount.com']);

        $result = Utils::findObjectsByRecipientAddress('test@UserAccount.com');

        $this->assertCount(1, $result);
        $this->assertSame($user->email, $result[0]->email);

        $user->setAliases(['catchall@UserAccount.com']);

        $result = Utils::findObjectsByRecipientAddress('unknown@UserAccount.com');

        $this->assertCount(1, $result);
        $this->assertSame($user->email, $result[0]->email);

        // Shared folders

        $folder = $this->getTestSharedFolder('folder-test@kolabnow.com');
        $folder->setAliases(['test@kolabnow.com']);

        $result = Utils::findObjectsByRecipientAddress('folder-test@kolabnow.com');

        $this->assertCount(1, $result);
        $this->assertSame($folder->email, $result[0]->email);

        $result = Utils::findObjectsByRecipientAddress('test@kolabnow.com');

        $this->assertCount(1, $result);
        $this->assertSame($folder->email, $result[0]->email);

        $folder->setAliases(['catchall@kolabnow.com']);

        $result = Utils::findObjectsByRecipientAddress('unknown@kolabnow.com');

        $this->assertCount(1, $result);
        $this->assertSame($folder->email, $result[0]->email);

        // Resources

        $resource = $this->getTestResource('resource-test@kolabnow.com');

        $result = Utils::findObjectsByRecipientAddress('resource-test@kolabnow.com');

        $this->assertCount(1, $result);
        $this->assertSame($resource->email, $result[0]->email);

        // TODO: Test multiple entries result
    }
}
