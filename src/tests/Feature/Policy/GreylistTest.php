<?php

namespace Tests\Feature\Policy;

use App\Domain;
use App\IP4Net;
use App\Policy\Greylist;
use App\User;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * @group data
 */
class GreylistTest extends TestCase
{
    private $clientAddress;
    private $net;
    private $testResource;
    private $testSharedFolder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientAddress = '212.103.80.148';
        $this->net = IP4Net::getNet($this->clientAddress);

        $this->domainHosted = $this->getTestDomain('test.domain', [
            'type' => Domain::TYPE_EXTERNAL,
            'status' => Domain::STATUS_ACTIVE | Domain::STATUS_CONFIRMED | Domain::STATUS_VERIFIED,
        ]);
        $this->getTestDomain('test2.domain2', [
            'type' => Domain::TYPE_EXTERNAL,
            'status' => Domain::STATUS_ACTIVE | Domain::STATUS_CONFIRMED | Domain::STATUS_VERIFIED,
        ]);

        $this->domainOwner = $this->getTestUser('john@test.domain');

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();
        Greylist\Whitelist::where('sender_domain', 'sender.domain')->delete();
    }

    protected function tearDown(): void
    {
        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();
        Greylist\Whitelist::where('sender_domain', 'sender.domain')->delete();

        if ($this->testSharedFolder) {
            $this->deleteTestSharedFolder($this->testSharedFolder->email);
        }
        if ($this->testResource) {
            $this->deleteTestResource($this->testResource->email);
        }

        parent::tearDown();
    }

    /**
     * Test shouldDefer() method
     */
    public function testShouldDefer()
    {
        // Test no net
        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => $this->domainOwner->email,
            'client_address' => '128.128.129.130',
            'client_name' => 'some.mx',
        ]);

        $this->assertTrue($request->shouldDefer());

        // Test IPv6 net
        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => $this->domainOwner->email,
            'client_address' => '2a00:1450:400a:803::2005',
            'client_name' => 'some.mx',
        ]);

        $this->assertTrue($request->shouldDefer());

        // Test a new whitelist
        $whitelist = Greylist\Whitelist::where('sender_domain', 'sender.domain')->first();

        $this->assertNull($whitelist);

        for ($i = 0; $i < 5; $i++) {
            $request = new Greylist([
                'sender' => "someone{$i}@sender.domain",
                'recipient' => $this->domainOwner->email,
                'client_address' => $this->clientAddress,
                'client_name' => 'some.mx',
                'timestamp' => Carbon::now()->subDays(1),
            ]);

            $this->assertTrue($request->shouldDefer());
        }

        $whitelist = Greylist\Whitelist::where('sender_domain', 'sender.domain')->first();

        $this->assertNotNull($whitelist);

        $request = new Greylist([
            'sender' => "someone5@sender.domain",
            'recipient' => $this->domainOwner->email,
            'client_address' => $this->clientAddress,
            'client_name' => 'some.mx',
            'timestamp' => Carbon::now()->subDays(1),
        ]);

        $this->assertFalse($request->shouldDefer());

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();
        Greylist\Whitelist::where('sender_domain', 'sender.domain')->delete();

        // Test a stale whitelist
        for ($i = 0; $i < 5; $i++) {
            $request = new Greylist([
                'sender' => "someone{$i}@sender.domain",
                'recipient' => $this->domainOwner->email,
                'client_address' => $this->clientAddress,
                'client_name' => 'some.mx',
                'timestamp' => Carbon::now()->subDays(1),
            ]);

            $this->assertTrue($request->shouldDefer());
        }

        $whitelist = Greylist\Whitelist::where('sender_domain', 'sender.domain')->first();

        $this->assertNotNull($whitelist);

        $request = new Greylist([
            'sender' => "someone5@sender.domain",
            'recipient' => $this->domainOwner->email,
            'client_address' => $this->clientAddress,
            'client_name' => 'some.mx',
            'timestamp' => Carbon::now()->subDays(1),
        ]);

        $this->assertFalse($request->shouldDefer());

        $whitelist->updated_at = Carbon::now()->subMonthsWithoutOverflow(2);
        $whitelist->save(['timestamps' => false]);

        $this->assertTrue($request->shouldDefer());

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();
        Greylist\Whitelist::where('sender_domain', 'sender.domain')->delete();

        // test retry
        $connect = Greylist\Connect::create([
            'sender_local' => 'someone',
            'sender_domain' => 'sender.domain',
            'recipient_hash' => hash('sha256', $this->domainOwner->email),
            'recipient_id' => $this->domainOwner->id,
            'recipient_type' => User::class,
            'connect_count' => 1,
            'net_id' => $this->net->id,
            'net_type' => IP4Net::class,
        ]);

        $connect->created_at = Carbon::now()->subMinutes(6);
        $connect->save();

        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => $this->domainOwner->email,
            'client_address' => $this->clientAddress,
        ]);

        $this->assertFalse($request->shouldDefer());

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();

        // Test invalid recipient
        $connect = Greylist\Connect::create([
            'sender_local' => 'someone',
            'sender_domain' => 'sender.domain',
            'recipient_hash' => hash('sha256', $this->domainOwner->email),
            'recipient_id' => 1234,
            'recipient_type' => Domain::class,
            'connect_count' => 1,
            'net_id' => $this->net->id,
            'net_type' => IP4Net::class,
        ]);

        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => 'not.someone@that.exists',
            'client_address' => $this->clientAddress,
        ]);

        $this->assertTrue($request->shouldDefer());

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();

        // Test user disabled
        $connect = Greylist\Connect::create([
            'sender_local' => 'someone',
            'sender_domain' => 'sender.domain',
            'recipient_hash' => hash('sha256', $this->domainOwner->email),
            'recipient_id' => $this->domainOwner->id,
            'recipient_type' => User::class,
            'connect_count' => 1,
            'net_id' => $this->net->id,
            'net_type' => IP4Net::class,
        ]);

        $this->domainOwner->setSetting('greylist_enabled', 'false');

        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => $this->domainOwner->email,
            'client_address' => $this->clientAddress,
        ]);

        $this->assertFalse($request->shouldDefer());

        // Ensure we also find the setting by alias
        $this->domainOwner->setAliases(['alias1@test2.domain2']);

        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => 'alias1@test2.domain2',
            'client_address' => $this->clientAddress,
        ]);

        $this->assertFalse($request->shouldDefer());

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();

        // Test user enabled
        $connect = Greylist\Connect::create([
            'sender_local' => 'someone',
            'sender_domain' => 'sender.domain',
            'recipient_hash' => hash('sha256', $this->domainOwner->email),
            'recipient_id' => $this->domainOwner->id,
            'recipient_type' => User::class,
            'connect_count' => 1,
            'net_id' => $this->net->id,
            'net_type' => IP4Net::class,
        ]);

        $this->domainOwner->setSetting('greylist_enabled', 'true');

        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => $this->domainOwner->email,
            'client_address' => $this->clientAddress,
        ]);

        $this->assertTrue($request->shouldDefer());

        $connect->created_at = Carbon::now()->subMinutes(6);
        $connect->save();

        $this->assertFalse($request->shouldDefer());

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();

        // Test controller new
        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => $this->domainOwner->email,
            'client_address' => $this->clientAddress,
            'client_name' => 'some.mx',
        ]);

        $this->assertTrue($request->shouldDefer());

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();
        Greylist\Whitelist::where('sender_domain', 'sender.domain')->delete();

        // test controller not new
        $connect = Greylist\Connect::create([
            'sender_local' => 'someone',
            'sender_domain' => 'sender.domain',
            'recipient_hash' => hash('sha256', $this->domainOwner->email),
            'recipient_id' => $this->domainOwner->id,
            'recipient_type' => User::class,
            'connect_count' => 1,
            'net_id' => $this->net->id,
            'net_type' => IP4Net::class,
        ]);

        $connect->created_at = Carbon::now()->subMinutes(6);
        $connect->save();

        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => $this->domainOwner->email,
            'client_address' => $this->clientAddress,
            'client_name' => 'some.mx',
        ]);

        $this->assertFalse($request->shouldDefer());
    }

    /**
     * Test shouldDefer() for multiple users case
     */
    public function testMultipleUsersAllDisabled()
    {
        $this->setUpTest();

        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => $this->domainOwner->email,
            'client_address' => $this->clientAddress,
        ]);

        foreach ($this->domainUsers as $user) {
            Greylist\Connect::create([
                'sender_local' => 'someone',
                'sender_domain' => 'sender.domain',
                'recipient_hash' => hash('sha256', $user->email),
                'recipient_id' => $user->id,
                'recipient_type' => User::class,
                'connect_count' => 1,
                'net_id' => $this->net->id,
                'net_type' => IP4Net::class,
            ]);

            $user->setSetting('greylist_enabled', 'false');

            if ($user->email == $this->domainOwner->email) {
                continue;
            }

            $request = new Greylist([
                'sender' => 'someone@sender.domain',
                'recipient' => $user->email,
                'client_address' => $this->clientAddress,
            ]);

            $this->assertFalse($request->shouldDefer());
        }
    }

    /**
     * Test shouldDefer() for multiple users case
     */
    public function testMultipleUsersAnyEnabled()
    {
        $this->setUpTest();

        $request = new Greylist([
            'sender' => 'someone@sender.domain',
            'recipient' => $this->domainOwner->email,
            'client_address' => $this->clientAddress,
        ]);

        foreach ($this->domainUsers as $user) {
            Greylist\Connect::create([
                'sender_local' => 'someone',
                'sender_domain' => 'sender.domain',
                'recipient_hash' => hash('sha256', $user->email),
                'recipient_id' => $user->id,
                'recipient_type' => User::class,
                'connect_count' => 1,
                'net_id' => $this->net->id,
                'net_type' => IP4Net::class,
            ]);

            $user->setSetting('greylist_enabled', ($user->id == $this->jack->id) ? 'true' : 'false');

            if ($user->email == $this->domainOwner->email) {
                continue;
            }

            $request = new Greylist([
                'sender' => 'someone@sender.domain',
                'recipient' => $user->email,
                'client_address' => $this->clientAddress,
            ]);

            if ($user->id == $this->jack->id) {
                $this->assertTrue($request->shouldDefer());
            } else {
                $this->assertFalse($request->shouldDefer());
            }
        }
    }

    /**
     * Test shouldDefer() method against non-user recipient
     */
    public function testShouldDeferSharedFolder()
    {
        $this->testSharedFolder = $this->getTestSharedFolder('folder-test@kolabnow.com');
        $this->testSharedFolder->assignToWallet($this->domainOwner->wallets->first());
        $this->testResource = $this->getTestResource('resource-test@kolabnow.com');
        $this->testResource->assignToWallet($this->domainOwner->wallets->first());

        // Test a SharedFolder recipient
        $whitelist = Greylist\Whitelist::where('sender_domain', 'sender.domain')->first();
        $this->assertNull($whitelist);

        for ($i = 0; $i < 5; $i++) {
            $request = new Greylist([
                'sender' => "someone{$i}@sender.domain",
                'recipient' => $this->testSharedFolder->email,
                'client_address' => $this->clientAddress,
                'client_name' => 'some.mx',
                'timestamp' => Carbon::now()->subDays(1),
            ]);

            $this->assertTrue($request->shouldDefer());
        }

        $whitelist = Greylist\Whitelist::where('sender_domain', 'sender.domain')->first();
        $this->assertNotNull($whitelist);

        $request = new Greylist([
            'sender' => "someone5@sender.domain",
            'recipient' => $this->testSharedFolder->email,
            'client_address' => $this->clientAddress,
            'client_name' => 'some.mx',
            'timestamp' => Carbon::now()->subDays(1),
        ]);

        $this->assertFalse($request->shouldDefer());

        Greylist\Connect::where('sender_domain', 'sender.domain')->delete();
        Greylist\Whitelist::where('sender_domain', 'sender.domain')->delete();

        // Test a Resource recipient
        $whitelist = Greylist\Whitelist::where('sender_domain', 'sender.domain')->first();
        $this->assertNull($whitelist);

        for ($i = 0; $i < 5; $i++) {
            $request = new Greylist([
                'sender' => "someone{$i}@sender.domain",
                'recipient' => $this->testResource->email,
                'client_address' => $this->clientAddress,
                'client_name' => 'some.mx',
                'timestamp' => Carbon::now()->subDays(1),
            ]);

            $this->assertTrue($request->shouldDefer());
        }

        $whitelist = Greylist\Whitelist::where('sender_domain', 'sender.domain')->first();
        $this->assertNotNull($whitelist);

        $request = new Greylist([
            'sender' => "someone5@sender.domain",
            'recipient' => $this->testResource->email,
            'client_address' => $this->clientAddress,
            'client_name' => 'some.mx',
            'timestamp' => Carbon::now()->subDays(1),
        ]);

        $this->assertFalse($request->shouldDefer());
    }
}
