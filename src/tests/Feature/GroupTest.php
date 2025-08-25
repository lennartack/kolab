<?php

namespace Tests\Feature;

use App\Entitlement;
use App\EventLog;
use App\Group;
use App\Jobs\Group\CreateJob;
use App\Jobs\Group\DeleteJob;
use App\Jobs\Group\UpdateJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GroupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('user-test@kolabnow.com');
        $this->deleteTestGroup('group-test@kolabnow.com');
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('user-test@kolabnow.com');
        $this->deleteTestGroup('group-test@kolabnow.com');

        parent::tearDown();
    }

    /**
     * Test Group::addAddresses()
     */
    public function testAddAddresses(): void
    {
        Queue::fake();

        $group = $this->getTestGroup('group-test@kolabnow.com');

        $group->setAddresses(['test@gmail.com']);

        Queue::assertPushed(\App\Jobs\Group\UpdateJob::class, 1);
        $this->assertSame(['test@gmail.com'], $group->getAddresses());

        $group->setAddresses(['test@gmail.com']);

        Queue::assertPushed(\App\Jobs\Group\UpdateJob::class, 1);
        $this->assertSame(['test@gmail.com'], $group->getAddresses());

        $group->setAddresses([]);

        Queue::assertPushed(\App\Jobs\Group\UpdateJob::class, 2);
        $this->assertSame([], $group->getAddresses());

        $group->setAddresses(['test1@gmail.com', 'test2@gmail.com'], true);

        Queue::assertPushed(\App\Jobs\Group\UpdateJob::class, 2);
        $this->assertSame(['test1@gmail.com', 'test2@gmail.com'], $group->getAddresses());

        $group->setAddresses(['test1@gmail.com', 'test3@gmail.com']);

        Queue::assertPushed(\App\Jobs\Group\UpdateJob::class, 3);
        $this->assertSame(['test1@gmail.com', 'test3@gmail.com'], $group->getAddresses());

        $group->delete();
        $group->setAddresses(['test1@gmail.com']);

        Queue::assertPushed(\App\Jobs\Group\UpdateJob::class, 3);
        $this->assertSame(['test1@gmail.com'], $group->getAddresses());
    }

    /**
     * Tests for Group::assignToWallet()
     */
    public function testAssignToWallet(): void
    {
        $user = $this->getTestUser('user-test@kolabnow.com');
        $group = $this->getTestGroup('group-test@kolabnow.com');

        $result = $group->assignToWallet($user->wallets->first());

        $this->assertSame($group, $result);
        $this->assertSame(1, $group->entitlements()->count());

        // Can't be done twice on the same group
        $this->expectException(\Exception::class);
        $result->assignToWallet($user->wallets->first());
    }

    /**
     * Test Group::getConfig() and setConfig() methods
     */
    public function testConfigTrait(): void
    {
        $group = $this->getTestGroup('group-test@kolabnow.com');

        $group->setSetting('sender_policy', '["test","-"]');

        $this->assertSame(['sender_policy' => ['test']], $group->getConfig());

        $result = $group->setConfig(['sender_policy' => [], 'unknown' => false]);

        $this->assertSame(['sender_policy' => []], $group->getConfig());
        $this->assertSame('[]', $group->getSetting('sender_policy'));
        $this->assertSame(['unknown' => "The requested configuration parameter is not supported."], $result);

        $result = $group->setConfig(['sender_policy' => ['test']]);

        $this->assertSame(['sender_policy' => ['test']], $group->getConfig());
        $this->assertSame('["test","-"]', $group->getSetting('sender_policy'));
        $this->assertSame([], $result);
    }

    /**
     * Test creating a group
     */
    public function testCreate(): void
    {
        Queue::fake();

        $group = Group::create(['email' => 'GROUP-test@kolabnow.com']);

        $this->assertSame('group-test@kolabnow.com', $group->email);
        $this->assertSame('group-test', $group->name);
        $this->assertMatchesRegularExpression('/^[0-9]{1,20}$/', (string) $group->id);
        $this->assertTrue($group->isNew());
        $this->assertFalse($group->isActive());

        Queue::assertPushed(
            CreateJob::class,
            static function ($job) use ($group) {
                $groupEmail = TestCase::getObjectProperty($job, 'groupEmail');
                $groupId = TestCase::getObjectProperty($job, 'groupId');

                return $groupEmail === $group->email
                    && $groupId === $group->id;
            }
        );
    }

    /**
     * Test group deletion and force-deletion
     */
    public function testDelete(): void
    {
        Queue::fake();

        $user = $this->getTestUser('user-test@kolabnow.com');
        $group = $this->getTestGroup('group-test@kolabnow.com');
        $group->assignToWallet($user->wallets->first());

        $entitlements = Entitlement::where('entitleable_id', $group->id);

        $this->assertSame(1, $entitlements->count());

        $group->delete();

        $this->assertTrue($group->fresh()->trashed());
        $this->assertSame(0, $entitlements->count());
        $this->assertSame(1, $entitlements->withTrashed()->count());

        Queue::assertPushed(UpdateJob::class, 0);
        Queue::assertPushed(DeleteJob::class, 1);
        Queue::assertPushed(
            DeleteJob::class,
            static function ($job) use ($group) {
                $groupEmail = TestCase::getObjectProperty($job, 'groupEmail');
                $groupId = TestCase::getObjectProperty($job, 'groupId');

                return $groupEmail === $group->email
                    && $groupId === $group->id;
            }
        );

        Queue::fake();

        $group->forceDelete();

        $this->assertSame(0, $entitlements->withTrashed()->count());
        $this->assertCount(0, Group::withTrashed()->where('id', $group->id)->get());

        Queue::assertPushed(UpdateJob::class, 0);
        Queue::assertPushed(DeleteJob::class, 0);
    }

    /**
     * Test eventlog on group deletion
     */
    public function testDeleteAndEventLog(): void
    {
        Queue::fake();

        $group = $this->getTestGroup('group-test@kolabnow.com');

        EventLog::createFor($group, EventLog::TYPE_SUSPENDED, 'test');

        $group->delete();

        $this->assertCount(1, EventLog::where('object_id', $group->id)->where('object_type', Group::class)->get());

        $group->forceDelete();

        $this->assertCount(0, EventLog::where('object_id', $group->id)->where('object_type', Group::class)->get());
    }

    /**
     * Tests for Group::emailExists()
     */
    public function testEmailExists(): void
    {
        Queue::fake();

        $group = $this->getTestGroup('group-test@kolabnow.com');

        $this->assertFalse(Group::emailExists('unknown@domain.tld'));
        $this->assertTrue(Group::emailExists($group->email));

        $result = Group::emailExists($group->email, true);
        $this->assertSame($result->id, $group->id);

        $group->delete();

        $this->assertTrue(Group::emailExists($group->email));

        $result = Group::emailExists($group->email, true);
        $this->assertSame($result->id, $group->id);
    }

    // Test group restoring
    public function testRestore(): void
    {
        Queue::fake();

        $user = $this->getTestUser('user-test@kolabnow.com');
        $group = $this->getTestGroup('group-test@kolabnow.com', [
            'status' => Group::STATUS_ACTIVE | Group::STATUS_LDAP_READY | Group::STATUS_SUSPENDED,
        ]);
        $group->assignToWallet($user->wallets->first());

        $entitlements = Entitlement::where('entitleable_id', $group->id);

        $this->assertTrue($group->isSuspended());
        if (\config('app.with_ldap')) {
            $this->assertTrue($group->isLdapReady());
        }
        $this->assertTrue($group->isActive());
        $this->assertSame(1, $entitlements->count());

        $group->delete();

        $this->assertTrue($group->fresh()->trashed());
        $this->assertSame(0, $entitlements->count());
        $this->assertSame(1, $entitlements->withTrashed()->count());

        Queue::fake();

        $group->restore();
        $group->refresh();

        $this->assertFalse($group->trashed());
        $this->assertFalse($group->isDeleted());
        $this->assertFalse($group->isSuspended());
        if (\config('app.with_ldap')) {
            $this->assertFalse($group->isLdapReady());
        }
        $this->assertFalse($group->isActive());
        $this->assertTrue($group->isNew());

        $this->assertSame(1, $entitlements->count());
        $entitlements->get()->each(function ($ent) {
            $this->assertTrue($ent->updated_at->greaterThan(Carbon::now()->subSeconds(5)));
        });

        Queue::assertPushed(CreateJob::class, 1);
        Queue::assertPushed(
            CreateJob::class,
            static function ($job) use ($group) {
                $groupEmail = TestCase::getObjectProperty($job, 'groupEmail');
                $groupId = TestCase::getObjectProperty($job, 'groupId');

                return $groupEmail === $group->email
                    && $groupId === $group->id;
            }
        );
    }

    /**
     * Tests for GroupSettingsTrait functionality and GroupSettingObserver
     */
    public function testSettings(): void
    {
        Queue::fake();
        Queue::assertNothingPushed();

        $group = $this->getTestGroup('group-test@kolabnow.com');

        Queue::assertPushed(UpdateJob::class, 0);

        // Add a setting
        $group->setSetting('unknown', 'test');

        Queue::assertPushed(UpdateJob::class, 0);

        // Add a setting that is synced to LDAP
        $group->setSetting('sender_policy', '[]');

        if (\config('app.with_ldap')) {
            Queue::assertPushed(UpdateJob::class, 1);
        }

        // Note: We test both current group as well as fresh group object
        //       to make sure cache works as expected
        $this->assertSame('test', $group->getSetting('unknown'));
        $this->assertSame('[]', $group->fresh()->getSetting('sender_policy'));

        Queue::fake();

        // Update a setting
        $group->setSetting('unknown', 'test1');

        Queue::assertPushed(UpdateJob::class, 0);

        // Update a setting that is synced to LDAP
        $group->setSetting('sender_policy', '["-"]');

        if (\config('app.with_ldap')) {
            Queue::assertPushed(UpdateJob::class, 1);
        }

        $this->assertSame('test1', $group->getSetting('unknown'));
        $this->assertSame('["-"]', $group->fresh()->getSetting('sender_policy'));

        Queue::fake();

        // Delete a setting (null)
        $group->setSetting('unknown', null);

        Queue::assertPushed(UpdateJob::class, 0);

        // Delete a setting that is synced to LDAP
        $group->setSetting('sender_policy', null);

        if (\config('app.with_ldap')) {
            Queue::assertPushed(UpdateJob::class, 1);
        }

        $this->assertNull($group->getSetting('unknown'));
        $this->assertNull($group->fresh()->getSetting('sender_policy'));
    }

    /**
     * Test group status assignment and is*() methods
     */
    public function testStatus(): void
    {
        $group = new Group();

        $this->assertFalse($group->isNew());
        $this->assertFalse($group->isActive());
        $this->assertFalse($group->isDeleted());
        if (\config('app.with_ldap')) {
            $this->assertFalse($group->isLdapReady());
        }
        $this->assertFalse($group->isSuspended());

        $group->status = Group::STATUS_NEW;

        $this->assertTrue($group->isNew());
        $this->assertFalse($group->isActive());
        $this->assertFalse($group->isDeleted());
        if (\config('app.with_ldap')) {
            $this->assertFalse($group->isLdapReady());
        }
        $this->assertFalse($group->isSuspended());

        $group->status |= Group::STATUS_ACTIVE;

        $this->assertTrue($group->isNew());
        $this->assertTrue($group->isActive());
        $this->assertFalse($group->isDeleted());
        if (\config('app.with_ldap')) {
            $this->assertFalse($group->isLdapReady());
        }
        $this->assertFalse($group->isSuspended());

        if (\config('app.with_ldap')) {
            $group->status |= Group::STATUS_LDAP_READY;
        }

        $this->assertTrue($group->isNew());
        $this->assertTrue($group->isActive());
        $this->assertFalse($group->isDeleted());

        if (\config('app.with_ldap')) {
            $this->assertTrue($group->isLdapReady());
        }
        $this->assertFalse($group->isSuspended());

        $group->status |= Group::STATUS_DELETED;

        $this->assertTrue($group->isNew());
        $this->assertTrue($group->isActive());
        $this->assertTrue($group->isDeleted());
        if (\config('app.with_ldap')) {
            $this->assertTrue($group->isLdapReady());
        }
        $this->assertFalse($group->isSuspended());

        $group->status |= Group::STATUS_SUSPENDED;

        $this->assertTrue($group->isNew());
        $this->assertTrue($group->isActive());
        $this->assertTrue($group->isDeleted());
        if (\config('app.with_ldap')) {
            $this->assertTrue($group->isLdapReady());
        }
        $this->assertTrue($group->isSuspended());

        // Unknown status value
        $this->expectException(\Exception::class);
        $group->status = 111;
    }

    /**
     * Tests for Group::suspend()
     */
    public function testSuspend(): void
    {
        Queue::fake();

        $group = $this->getTestGroup('group-test@kolabnow.com');
        $group->suspend();

        $this->assertTrue($group->isSuspended());

        Queue::assertPushed(UpdateJob::class, 1);
        Queue::assertPushed(
            UpdateJob::class,
            static function ($job) use ($group) {
                $groupEmail = TestCase::getObjectProperty($job, 'groupEmail');
                $groupId = TestCase::getObjectProperty($job, 'groupId');

                return $groupEmail === $group->email
                    && $groupId === $group->id;
            }
        );
    }

    /**
     * Test updating a group
     */
    public function testUpdate(): void
    {
        Queue::fake();

        $group = $this->getTestGroup('group-test@kolabnow.com');
        $group->status |= Group::STATUS_DELETED;
        $group->save();

        Queue::assertPushed(UpdateJob::class, 1);
        Queue::assertPushed(
            UpdateJob::class,
            static function ($job) use ($group) {
                $groupEmail = TestCase::getObjectProperty($job, 'groupEmail');
                $groupId = TestCase::getObjectProperty($job, 'groupId');

                return $groupEmail === $group->email
                    && $groupId === $group->id;
            }
        );
    }

    /**
     * Tests for Group::unsuspend()
     */
    public function testUnsuspend(): void
    {
        Queue::fake();

        $group = $this->getTestGroup('group-test@kolabnow.com');
        $group->status = Group::STATUS_SUSPENDED;
        $group->unsuspend();

        $this->assertFalse($group->isSuspended());

        Queue::assertPushed(UpdateJob::class, 1);
        Queue::assertPushed(
            UpdateJob::class,
            static function ($job) use ($group) {
                $groupEmail = TestCase::getObjectProperty($job, 'groupEmail');
                $groupId = TestCase::getObjectProperty($job, 'groupId');

                return $groupEmail === $group->email
                    && $groupId === $group->id;
            }
        );
    }
}
