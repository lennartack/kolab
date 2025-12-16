<?php

namespace Tests;

use App\Auth\SecondFactor;
use App\Backends\IMAP;
use App\Backends\LDAP;
use App\CompanionApp;
use App\Domain;
use App\Entitlement;
use App\Group;
use App\Jobs\User\CreateJob;
use App\Meet\Room;
use App\Package;
use App\Resource;
use App\SharedFolder;
use App\Sku;
use App\Transaction;
use App\User;
use App\Utils;
use App\Wallet;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Assert;

trait TestCaseTrait
{
    /**
     * A domain that is hosted.
     *
     * @var ?Domain
     */
    protected $domainHosted;

    /**
     * A domain that is hosted.
     *
     * @var ?Domain
     */
    protected $domainHosted2;

    /**
     * The hosted domain owner.
     *
     * @var ?User
     */
    protected $domainOwner;

    /**
     * Some profile details for an owner of a domain
     *
     * @var array
     */
    protected $domainOwnerSettings = [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'organization' => 'Test Domain Owner',
    ];

    /**
     * Some users for the hosted domain, ultimately including the owner.
     *
     * @var User[]
     */
    protected $domainUsers = [];

    /**
     * A specific user that is a regular user in the hosted domain.
     *
     * @var ?User
     */
    protected $jack;

    /**
     * A specific user that is a controller on the wallet to which the hosted domain is charged.
     *
     * @var ?User
     */
    protected $jane;

    /**
     * A specific user that has a second factor configured.
     *
     * @var ?User
     */
    protected $joe;

    /**
     * One of the domains that is available for public registration.
     *
     * @var ?Domain
     */
    protected $publicDomain;

    /**
     * A newly generated user in a public domain.
     *
     * @var ?User
     */
    protected $publicDomainUser;

    /**
     * A placeholder for a password that can be generated.
     *
     * Should be generated with `\App\Utils::generatePassphrase()`.
     *
     * @var ?string
     */
    protected $userPassword;

    /**
     * Register the beta entitlement for a user
     */
    protected function addBetaEntitlement($user, $titles = []): void
    {
        // Add beta + $title entitlements
        $beta_sku = Sku::withEnvTenantContext()->where('title', 'beta')->first();
        $user->assignSku($beta_sku);

        if (!empty($titles)) {
            Sku::withEnvTenantContext()->whereIn('title', (array) $titles)->get()
                ->each(static function ($sku) use ($user) {
                    $user->assignSku($sku);
                });
        }
    }

    /**
     * Assert that the entitlements for the user match the expected list of entitlements.
     *
     * @param User|Domain $object   the object for which the entitlements need to be pulled
     * @param array       $expected an array of expected \App\Sku titles
     */
    protected function assertEntitlements($object, $expected)
    {
        // Assert the user entitlements
        $skus = $object->entitlements()->get()
            ->map(static function ($ent) {
                return $ent->sku->title;
            })
            ->toArray();

        sort($skus);

        Assert::assertSame($expected, $skus);
    }

    /**
     * Assert content of the SKU element in an API response
     *
     * @param string $sku_title The SKU title
     * @param array  $result    The result to assert
     * @param array  $other     Other items the SKU itself does not include
     */
    protected function assertSkuElement($sku_title, $result, $other = []): void
    {
        $sku = Sku::withEnvTenantContext()->where('title', $sku_title)->first();

        $this->assertSame($sku->id, $result['id']);
        $this->assertSame($sku->title, $result['title']);
        $this->assertSame($sku->name, $result['name']);
        $this->assertSame($sku->description, $result['description']);
        $this->assertSame($sku->cost, $result['cost']);
        $this->assertSame($sku->units_free, $result['units_free']);
        $this->assertSame($sku->period, $result['period']);
        $this->assertSame($sku->active, $result['active']);

        foreach ($other as $key => $value) {
            $this->assertSame($value, $result[$key]);
        }
    }

    /**
     * Set a specific date to existing entitlements
     */
    protected function backdateEntitlements($entitlements, $targetDate, $targetCreatedDate = null): void
    {
        $wallets = [];
        $ids = [];

        foreach ($entitlements as $entitlement) {
            $ids[] = $entitlement->id;
            $wallets[] = $entitlement->wallet_id;
        }

        Entitlement::whereIn('id', $ids)->update([
            'created_at' => $targetCreatedDate ?: $targetDate,
            'updated_at' => $targetDate,
        ]);

        if (!empty($wallets)) {
            $wallets = array_unique($wallets);
            $owners = Wallet::whereIn('id', $wallets)->pluck('user_id')->all();

            User::whereIn('id', $owners)->update([
                'created_at' => $targetCreatedDate ?: $targetDate,
            ]);
        }
    }

    /**
     * Removes all beta entitlements from the database
     */
    protected function clearBetaEntitlements(): void
    {
        $beta_handlers = [
            'App\Handlers\Beta',
        ];

        $betas = Sku::whereIn('handler_class', $beta_handlers)->pluck('id')->all();

        Entitlement::whereIn('sku_id', $betas)->delete();
    }

    /**
     * Creates the application.
     *
     * @return Application
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Create a set of transaction log entries for a wallet
     */
    protected function createTestTransactions($wallet)
    {
        $result = [];
        $date = Carbon::now();
        $debit = 0;
        $entitlementTransactions = [];

        foreach ($wallet->entitlements as $entitlement) {
            if ($entitlement->cost) {
                $debit += $entitlement->cost;
                $entitlementTransactions[] = $entitlement->createTransaction(
                    Transaction::ENTITLEMENT_BILLED,
                    $entitlement->cost
                );
            }
        }

        $transaction = Transaction::create(
            [
                'user_email' => 'jeroen@jeroen.jeroen',
                'object_id' => $wallet->id,
                'object_type' => Wallet::class,
                'type' => Transaction::WALLET_DEBIT,
                'amount' => $debit * -1,
                'description' => 'Payment',
            ]
        );

        $result[] = $transaction;

        Transaction::whereIn('id', $entitlementTransactions)->update(['transaction_id' => $transaction->id]);

        $transaction = Transaction::create(
            [
                'user_email' => null,
                'object_id' => $wallet->id,
                'object_type' => Wallet::class,
                'type' => Transaction::WALLET_CREDIT,
                'amount' => 2000,
                'description' => 'Payment',
            ]
        );

        $transaction->created_at = $date->next(Carbon::MONDAY);
        $transaction->save();

        $result[] = $transaction;

        $types = [
            Transaction::WALLET_AWARD,
            Transaction::WALLET_PENALTY,
        ];

        // The page size is 10, so we generate so many to have at least two pages
        $loops = 10;
        while ($loops-- > 0) {
            $type = $types[count($result) % count($types)];
            $transaction = Transaction::create([
                'user_email' => 'jeroen.@jeroen.jeroen',
                'object_id' => $wallet->id,
                'object_type' => Wallet::class,
                'type' => $type,
                'amount' => 11 * (count($result) + 1) * ($type == Transaction::WALLET_PENALTY ? -1 : 1),
                'description' => 'TRANS' . $loops,
            ]);

            $transaction->created_at = $date->next(Carbon::MONDAY);
            $transaction->save();

            $result[] = $transaction;
        }

        return $result;
    }

    /**
     * Delete a test domain whatever it takes.
     *
     * @coversNothing
     */
    protected function deleteTestDomain($name, $backends = false)
    {
        Queue::fake();

        $domain = Domain::withTrashed()->where('namespace', $name)->first();

        if (!$domain) {
            return;
        }

        if ($backends && \config('app.with_ldap')) {
            LDAP::deleteDomain($domain);
        }

        $domain->forceDelete();
    }

    /**
     * Delete a test group whatever it takes.
     *
     * @coversNothing
     */
    protected function deleteTestGroup($email, $backends = false)
    {
        Queue::fake();

        $group = Group::withTrashed()->where('email', $email)->first();

        if (!$group) {
            return;
        }

        if ($backends && \config('app.with_ldap')) {
            LDAP::deleteGroup($group);
        }

        $group->forceDelete();
    }

    /**
     * Delete a test resource whatever it takes.
     *
     * @coversNothing
     */
    protected function deleteTestResource($email, $backends = false)
    {
        Queue::fake();

        $resource = Resource::withTrashed()->where('email', $email)->first();

        if (!$resource) {
            return;
        }

        if ($backends && \config('app.with_ldap')) {
            LDAP::deleteResource($resource);
        }

        $resource->forceDelete();
    }

    /**
     * Delete a test room whatever it takes.
     *
     * @coversNothing
     */
    protected function deleteTestRoom($name)
    {
        Queue::fake();

        $room = Room::withTrashed()->where('name', $name)->first();

        if (!$room) {
            return;
        }

        $room->forceDelete();
    }

    /**
     * Delete a test shared folder whatever it takes.
     *
     * @coversNothing
     */
    protected function deleteTestSharedFolder($email, $backends = false)
    {
        Queue::fake();

        $folder = SharedFolder::withTrashed()->where('email', $email)->first();

        if (!$folder) {
            return;
        }

        if ($backends && \config('app.with_ldap')) {
            LDAP::deleteSharedFolder($folder);
        }

        $folder->forceDelete();
    }

    /**
     * Delete a test user whatever it takes.
     *
     * @coversNothing
     */
    protected function deleteTestUser($email, $backends = false)
    {
        Queue::fake();

        $user = User::withTrashed()->where('email', $email)->first();

        if (!$user) {
            return;
        }

        if ($backends && \config('app.with_imap')) {
            IMAP::deleteUser($user);
        }
        if ($backends && \config('app.with_ldap')) {
            LDAP::deleteUser($user);
        }

        $user->forceDelete();
    }

    /**
     * Delete a test companion app whatever it takes.
     *
     * @coversNothing
     */
    protected function deleteTestCompanionApp($deviceId)
    {
        Queue::fake();

        $companionApp = CompanionApp::where('device_id', $deviceId)->first();

        if (!$companionApp) {
            return;
        }

        $companionApp->forceDelete();
    }

    /**
     * Helper to access protected property of an object
     */
    protected static function getObjectProperty($object, $property_name)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($property_name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    /**
     * Get Domain object by namespace, create it if needed.
     * Skip LDAP jobs.
     *
     * @coversNothing
     */
    protected function getTestDomain($name, $attrib = [])
    {
        // Disable jobs (i.e. skip LDAP oprations)
        Queue::fake();
        return Domain::firstOrCreate(['namespace' => $name], $attrib);
    }

    /**
     * Get Group object by email, create it if needed.
     * Skip LDAP jobs.
     */
    protected function getTestGroup($email, $attrib = [])
    {
        // Disable jobs (i.e. skip LDAP oprations)
        Queue::fake();
        return Group::firstOrCreate(['email' => $email], $attrib);
    }

    /**
     * Get Resource object by email, create it if needed.
     * Skip LDAP jobs.
     */
    protected function getTestResource($email, $attrib = [])
    {
        // Disable jobs (i.e. skip LDAP oprations)
        Queue::fake();

        $resource = Resource::where('email', $email)->first();

        if (!$resource) {
            [$local, $domain] = explode('@', $email, 2);

            $resource = new Resource();
            $resource->email = $email;
            $resource->domainName = $domain;

            if (!isset($attrib['name'])) {
                $resource->name = $local;
            }
        }

        foreach ($attrib as $key => $val) {
            $resource->{$key} = $val;
        }

        $resource->save();

        return $resource;
    }

    /**
     * Get Room object by name, create it if needed.
     *
     * @coversNothing
     */
    protected function getTestRoom($name, $wallet = null, $attrib = [], $config = [], $title = null)
    {
        $attrib['name'] = $name;
        $room = Room::create($attrib);

        if ($wallet) {
            $room->assignToWallet($wallet, $title);
        }

        if (!empty($config)) {
            $room->setConfig($config);
        }

        return $room;
    }

    /**
     * Get SharedFolder object by email, create it if needed.
     * Skip LDAP jobs.
     */
    protected function getTestSharedFolder($email, $attrib = [])
    {
        // Disable jobs (i.e. skip LDAP oprations)
        Queue::fake();

        $folder = SharedFolder::where('email', $email)->first();

        if (!$folder) {
            [$local, $domain] = explode('@', $email, 2);

            $folder = new SharedFolder();
            $folder->email = $email;
            $folder->domainName = $domain;

            if (!isset($attrib['name'])) {
                $folder->name = $local;
            }
        }

        foreach ($attrib as $key => $val) {
            $folder->{$key} = $val;
        }

        $folder->save();

        return $folder;
    }

    /**
     * Get User object by email, create it if needed.
     * Skip LDAP jobs.
     *
     * @coversNothing
     */
    protected function getTestUser($email, $attrib = [], $createInBackends = false)
    {
        Queue::fake();

        $user = User::withTrashed()->firstOrCreate(['email' => $email], $attrib);

        if ($user->trashed()) {
            // Note: we do not want to use user restore here
            User::where('id', $user->id)->forceDelete();
            $user = User::create(['email' => $email] + $attrib);
        }

        if ($createInBackends) {
            $user->assignSku(Sku::withObjectTenantContext($user)->where('title', 'mailbox')->first());
            $job = new CreateJob($user->id);
            $job->handle();
        }

        return $user;
    }

    /**
     * Get CompanionApp object by deviceId, create it if needed.
     * Skip LDAP jobs.
     *
     * @coversNothing
     */
    protected function getTestCompanionApp($deviceId, $user, $attrib = [])
    {
        // Disable jobs (i.e. skip LDAP oprations)
        Queue::fake();
        $companionApp = CompanionApp::firstOrCreate(
            [
                'device_id' => $deviceId,
                'user_id' => $user->id,
                'notification_token' => '',
                'mfa_enabled' => 1,
            ],
            $attrib
        );
        return $companionApp;
    }

    /**
     * Call protected/private method of a class.
     *
     * @param object $object     instantiated object that we will run method on
     * @param string $methodName Method name to call
     * @param array  $parameters array of parameters to pass into method
     *
     * @return mixed method return
     */
    protected function invokeMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass($object::class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    /**
     * Init fake queue. Release unique job locks.
     */
    protected function fakeQueueReset()
    {
        // Release all locks for ShouldBeUnique jobs. Works only with Redis cache.
        $db = \cache()->getStore()->lockConnection(); // @phpstan-ignore-line
        $prefix = $db->getOptions()->prefix?->getPrefix();

        foreach ($db->keys('*') as $key) {
            if (str_contains($key, 'laravel_unique_job')) {
                $db->del($prefix ? substr($key, strlen($prefix)) : $key);
            }
        }

        Queue::fake();
    }

    /**
     * Extract content of an email message.
     *
     * @param Mailable $mail Mailable object
     *
     * @return array Parsed message data:
     *               - 'plain': Plain text body
     *               - 'html: HTML body
     *               - 'subject': Mail subject
     */
    protected function renderMail(Mailable $mail): array
    {
        $mail->build(); // @phpstan-ignore-line

        $result = $this->invokeMethod($mail, 'renderForAssertions');

        return [
            'plain' => $result[1],
            'html' => $result[0],
            'subject' => $mail->subject,
        ];
    }

    /**
     * Reset a room after tests
     */
    public function resetTestRoom(string $room_name = 'john', $config = [])
    {
        $room = Room::where('name', $room_name)->first();
        $room->setSettings(['password' => null, 'locked' => null, 'nomedia' => null]);

        if ($room->session_id) {
            $room->session_id = null;
            $room->save();
        }

        if (!empty($config)) {
            $room->setConfig($config);
        }

        return $room;
    }

    protected function setUpTest()
    {
        $this->userPassword = Utils::generatePassphrase();

        $this->domainHosted = $this->getTestDomain(
            'test.domain',
            [
                'type' => Domain::TYPE_EXTERNAL,
                'status' => Domain::STATUS_ACTIVE | Domain::STATUS_CONFIRMED | Domain::STATUS_VERIFIED,
            ]
        );

        $this->domainHosted2 = $this->getTestDomain(
            'test2.domain2',
            [
                'type' => Domain::TYPE_EXTERNAL,
                'status' => Domain::STATUS_ACTIVE | Domain::STATUS_CONFIRMED | Domain::STATUS_VERIFIED,
            ]
        );

        $packageKolab = Package::where('title', 'kolab')->first();

        $this->domainOwner = $this->getTestUser('john@test.domain', ['password' => $this->userPassword]);
        $this->domainOwner->assignPackage($packageKolab);
        $this->domainOwner->setSettings($this->domainOwnerSettings);
        $this->domainOwner->setAliases(['alias1@test2.domain2']);

        // separate for regular user
        $this->jack = $this->getTestUser('jack@test.domain', ['password' => $this->userPassword]);

        // separate for wallet controller
        $this->jane = $this->getTestUser('jane@test.domain', ['password' => $this->userPassword]);

        $this->joe = $this->getTestUser('joe@test.domain', ['password' => $this->userPassword]);

        $this->domainUsers[] = $this->jack;
        $this->domainUsers[] = $this->jane;
        $this->domainUsers[] = $this->joe;
        $this->domainUsers[] = $this->getTestUser('jill@test.domain', ['password' => $this->userPassword]);

        foreach ($this->domainUsers as $user) {
            $this->domainOwner->assignPackage($packageKolab, $user);
        }

        $this->domainUsers[] = $this->domainOwner;

        // assign second factor to joe
        $this->joe->assignSku(Sku::where('title', '2fa')->first());
        SecondFactor::seed($this->joe->email);

        usort(
            $this->domainUsers,
            static function ($a, $b) {
                return $a->email <=> $b->email;
            }
        );

        $this->domainHosted->assignPackage(
            Package::where('title', 'domain-hosting')->first(),
            $this->domainOwner
        );

        $wallet = $this->domainOwner->wallets()->first();

        $wallet->addController($this->jane);

        $this->publicDomain = Domain::where('type', Domain::TYPE_PUBLIC)->first();
        $this->publicDomainUser = $this->getTestUser(
            'john@' . $this->publicDomain->namespace,
            ['password' => $this->userPassword]
        );

        $this->publicDomainUser->assignPackage($packageKolab);

        Cache::forget('duskconfig');
    }

    protected function tearDown(): void
    {
        foreach ($this->domainUsers as $user) {
            if ($user == $this->domainOwner) {
                continue;
            }

            $this->deleteTestUser($user->email);
        }

        if ($this->domainOwner) {
            $this->deleteTestUser($this->domainOwner->email);
        }

        if ($this->domainHosted) {
            $this->deleteTestDomain($this->domainHosted->namespace);
        }
        if ($this->domainHosted2) {
            $this->deleteTestDomain($this->domainHosted2->namespace);
        }

        if ($this->publicDomainUser) {
            $this->deleteTestUser($this->publicDomainUser->email);
        }

        Cache::forget('duskconfig');

        parent::tearDown();
    }
}
