<?php

namespace Tests\Feature;

use App\Auth\Utils as AuthUtils;
use App\AuthAttempt;
use App\Delegation;
use App\Domain;
use App\Entitlement;
use App\EventLog;
use App\Group;
use App\Jobs\PGP\KeyCreateJob;
use App\Jobs\PGP\KeyDeleteJob;
use App\Jobs\User\CreateJob;
use App\Jobs\User\DeleteJob;
use App\Jobs\User\UpdateJob;
use App\Package;
use App\Plan;
use App\Resource;
use App\SharedFolder;
use App\Sku;
use App\Tenant;
use App\TenantSetting;
use App\Transaction;
use App\User;
use App\Wallet;
use Carbon\Carbon;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('user-test@' . \config('app.domain'));
        $this->deleteTestUser('UserAccountA@UserAccount.com');
        $this->deleteTestUser('UserAccountB@UserAccount.com');
        $this->deleteTestUser('UserAccountC@UserAccount.com');
        $this->deleteTestGroup('test-group@UserAccount.com');
        $this->deleteTestResource('test-resource@UserAccount.com');
        $this->deleteTestSharedFolder('test-folder@UserAccount.com');
        $this->deleteTestDomain('UserAccount.com');
        $this->deleteTestDomain('UserAccountAdd.com');
        Package::where('title', 'test-package')->delete();
    }

    protected function tearDown(): void
    {
        TenantSetting::truncate();
        Package::where('title', 'test-package')->delete();
        $this->deleteTestUser('user-test@' . \config('app.domain'));
        $this->deleteTestUser('UserAccountA@UserAccount.com');
        $this->deleteTestUser('UserAccountB@UserAccount.com');
        $this->deleteTestUser('UserAccountC@UserAccount.com');
        $this->deleteTestGroup('test-group@UserAccount.com');
        $this->deleteTestResource('test-resource@UserAccount.com');
        $this->deleteTestSharedFolder('test-folder@UserAccount.com');
        $this->deleteTestDomain('UserAccount.com');
        $this->deleteTestDomain('UserAccountAdd.com');

        parent::tearDown();
    }

    /**
     * Tests for User::assignPackage()
     */
    public function testAssignPackage(): void
    {
        $user = $this->getTestUser('user-test@' . \config('app.domain'));
        $wallet = $user->wallets()->first();
        $skuGroupware = Sku::withEnvTenantContext()->where('title', 'groupware')->first();  // cost: 490
        $skuMailbox = Sku::withEnvTenantContext()->where('title', 'mailbox')->first();      // cost: 500
        $skuStorage = Sku::withEnvTenantContext()->where('title', 'storage')->first();      // cost: 25
        $package = Package::create([
            'title' => 'test-package',
            'name' => 'Test Account',
            'description' => 'Test account.',
            'discount_rate' => 0,
        ]);

        // WARNING: saveMany() sets package_skus.cost = skus.cost
        $package->skus()->saveMany([
            $skuMailbox,
            $skuGroupware,
            $skuStorage,
        ]);

        $package->skus()->updateExistingPivot($skuStorage, ['qty' => 2, 'cost' => null], false);
        $package->skus()->updateExistingPivot($skuMailbox, ['cost' => null], false);
        $package->skus()->updateExistingPivot($skuGroupware, ['cost' => 100], false);

        $user->assignPackage($package);

        $this->assertCount(4, $user->entitlements()->get()); // mailbox + groupware + 2 x storage

        $entitlement = $wallet->entitlements()->where('sku_id', $skuMailbox->id)->first();
        $this->assertSame($skuMailbox->id, $entitlement->sku->id);
        $this->assertSame($wallet->id, $entitlement->wallet->id);
        $this->assertSame($user->id, $entitlement->entitleable_id);
        $this->assertTrue($entitlement->entitleable instanceof User);
        $this->assertSame($skuMailbox->cost, $entitlement->cost);

        $entitlement = $wallet->entitlements()->where('sku_id', $skuGroupware->id)->first();
        $this->assertSame($skuGroupware->id, $entitlement->sku->id);
        $this->assertSame($wallet->id, $entitlement->wallet->id);
        $this->assertSame($user->id, $entitlement->entitleable_id);
        $this->assertTrue($entitlement->entitleable instanceof User);
        $this->assertSame(100, $entitlement->cost);

        $entitlement = $wallet->entitlements()->where('sku_id', $skuStorage->id)->first();
        $this->assertSame($skuStorage->id, $entitlement->sku->id);
        $this->assertSame($wallet->id, $entitlement->wallet->id);
        $this->assertSame($user->id, $entitlement->entitleable_id);
        $this->assertTrue($entitlement->entitleable instanceof User);
        $this->assertSame(0, $entitlement->cost);
    }

    /**
     * Tests for User::assignPlan()
     */
    public function testAssignPlan(): void
    {
        $domain = $this->getTestDomain('useraccount.com', [
            'status' => Domain::STATUS_NEW | Domain::STATUS_ACTIVE,
            'type' => Domain::TYPE_EXTERNAL,
        ]);
        $user = $this->getTestUser('useraccounta@' . $domain->namespace);
        $plan = Plan::withObjectTenantContext($user)->where('title', 'group')->first();

        // Group plan without domain
        $user->assignPlan($plan);

        $this->assertSame((string) $plan->id, $user->getSetting('plan_id'));
        $this->assertSame(7, $user->entitlements()->count()); // 5 storage + 1 mailbox + 1 groupware

        $user = $this->getTestUser('useraccountb@' . $domain->namespace);

        // Group plan with a domain
        $user->assignPlan($plan, $domain);

        $this->assertSame((string) $plan->id, $user->getSetting('plan_id'));
        $this->assertSame(7, $user->entitlements()->count()); // 5 storage + 1 mailbox + 1 groupware
        $this->assertSame(1, $domain->entitlements()->count());

        // Individual plan (domain is not allowed)
        $user = $this->getTestUser('user-test@' . \config('app.domain'));
        $plan = Plan::withObjectTenantContext($user)->where('title', 'individual')->first();

        $this->expectException(\Exception::class);
        $user->assignPlan($plan, $domain);

        $this->assertNull($user->getSetting('plan_id'));
        $this->assertSame(0, $user->entitlements()->count());
    }

    /**
     * Tests for User::assignSku()
     */
    public function testAssignSku(): void
    {
        $user = $this->getTestUser('user-test@' . \config('app.domain'));
        $wallet = $user->wallets()->first();
        $skuStorage = Sku::withEnvTenantContext()->where('title', 'storage')->first();
        $skuMailbox = Sku::withEnvTenantContext()->where('title', 'mailbox')->first();

        $user->assignSku($skuMailbox);

        $this->assertCount(1, $user->entitlements()->get());
        $entitlement = $wallet->entitlements()->where('sku_id', $skuMailbox->id)->first();
        $this->assertSame($skuMailbox->id, $entitlement->sku->id);
        $this->assertSame($wallet->id, $entitlement->wallet->id);
        $this->assertSame($user->id, $entitlement->entitleable_id);
        $this->assertTrue($entitlement->entitleable instanceof User);
        $this->assertSame($skuMailbox->cost, $entitlement->cost);

        // Test units_free handling
        for ($x = 0; $x < 5; $x++) {
            $user->assignSku($skuStorage);
        }

        $entitlements = $user->entitlements()->where('sku_id', $skuStorage->id)
            ->where('cost', 0)
            ->get();
        $this->assertCount(5, $entitlements);

        $user->assignSku($skuStorage);
        $entitlements = $user->entitlements()->where('sku_id', $skuStorage->id)
            ->where('cost', $skuStorage->cost)
            ->get();
        $this->assertCount(1, $entitlements);
    }

    /**
     * Verify a wallet assigned a controller is among the accounts of the assignee.
     */
    public function testAccounts(): void
    {
        $userA = $this->getTestUser('UserAccountA@UserAccount.com');
        $userB = $this->getTestUser('UserAccountB@UserAccount.com');

        $this->assertTrue($userA->wallets()->count() == 1);

        $userA->wallets()->each(
            static function ($wallet) use ($userB) {
                $wallet->addController($userB);
            }
        );

        $this->assertTrue($userB->accounts()->get()[0]->id === $userA->wallets()->get()[0]->id);
    }

    /**
     * Test User::canDelete() method
     */
    public function testCanDelete(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $domain = $this->getTestDomain('kolab.org');

        // Admin
        $this->assertTrue($admin->canDelete($admin));
        $this->assertFalse($admin->canDelete($john));
        $this->assertFalse($admin->canDelete($jack));
        $this->assertFalse($admin->canDelete($reseller1));
        $this->assertFalse($admin->canDelete($domain));
        $this->assertFalse($admin->canDelete($domain->wallet()));

        // Reseller - kolabnow
        $this->assertFalse($reseller1->canDelete($john));
        $this->assertFalse($reseller1->canDelete($jack));
        $this->assertTrue($reseller1->canDelete($reseller1));
        $this->assertFalse($reseller1->canDelete($domain));
        $this->assertFalse($reseller1->canDelete($domain->wallet()));
        $this->assertFalse($reseller1->canDelete($admin));

        // Normal user - account owner
        $this->assertTrue($john->canDelete($john));
        $this->assertTrue($john->canDelete($ned));
        $this->assertTrue($john->canDelete($jack));
        $this->assertTrue($john->canDelete($domain));
        $this->assertFalse($john->canDelete($domain->wallet()));
        $this->assertFalse($john->canDelete($reseller1));
        $this->assertFalse($john->canDelete($admin));

        // Normal user - a non-owner and non-controller
        $this->assertFalse($jack->canDelete($jack));
        $this->assertFalse($jack->canDelete($john));
        $this->assertFalse($jack->canDelete($domain));
        $this->assertFalse($jack->canDelete($domain->wallet()));
        $this->assertFalse($jack->canDelete($reseller1));
        $this->assertFalse($jack->canDelete($admin));

        // Normal user - John's wallet controller
        $this->assertTrue($ned->canDelete($ned));
        $this->assertFalse($ned->canDelete($john));
        $this->assertTrue($ned->canDelete($jack));
        $this->assertTrue($ned->canDelete($domain));
        $this->assertFalse($ned->canDelete($domain->wallet()));
        $this->assertFalse($ned->canDelete($reseller1));
        $this->assertFalse($ned->canDelete($admin));
    }

    /**
     * Test User::canRead() method
     */
    public function testCanRead(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $domain = $this->getTestDomain('kolab.org');

        // Admin
        $this->assertTrue($admin->canRead($admin));
        $this->assertTrue($admin->canRead($john));
        $this->assertTrue($admin->canRead($jack));
        $this->assertTrue($admin->canRead($reseller1));
        $this->assertTrue($admin->canRead($reseller2));
        $this->assertTrue($admin->canRead($domain));
        $this->assertTrue($admin->canRead($domain->wallet()));

        // Reseller - kolabnow
        $this->assertTrue($reseller1->canRead($john));
        $this->assertTrue($reseller1->canRead($jack));
        $this->assertTrue($reseller1->canRead($reseller1));
        $this->assertTrue($reseller1->canRead($domain));
        $this->assertTrue($reseller1->canRead($domain->wallet()));
        $this->assertFalse($reseller1->canRead($reseller2));
        $this->assertFalse($reseller1->canRead($admin));

        // Reseller - different tenant
        $this->assertTrue($reseller2->canRead($reseller2));
        $this->assertFalse($reseller2->canRead($john));
        $this->assertFalse($reseller2->canRead($jack));
        $this->assertFalse($reseller2->canRead($reseller1));
        $this->assertFalse($reseller2->canRead($domain));
        $this->assertFalse($reseller2->canRead($domain->wallet()));
        $this->assertFalse($reseller2->canRead($admin));

        // Normal user - account owner
        $this->assertTrue($john->canRead($john));
        $this->assertTrue($john->canRead($ned));
        $this->assertTrue($john->canRead($jack));
        $this->assertTrue($john->canRead($domain));
        $this->assertTrue($john->canRead($domain->wallet()));
        $this->assertFalse($john->canRead($reseller1));
        $this->assertFalse($john->canRead($reseller2));
        $this->assertFalse($john->canRead($admin));

        // Normal user - a non-owner and non-controller
        $this->assertTrue($jack->canRead($jack));
        $this->assertFalse($jack->canRead($john));
        $this->assertFalse($jack->canRead($domain));
        $this->assertFalse($jack->canRead($domain->wallet()));
        $this->assertFalse($jack->canRead($reseller1));
        $this->assertFalse($jack->canRead($reseller2));
        $this->assertFalse($jack->canRead($admin));

        // Normal user - John's wallet controller
        $this->assertTrue($ned->canRead($ned));
        $this->assertTrue($ned->canRead($john));
        $this->assertTrue($ned->canRead($jack));
        $this->assertTrue($ned->canRead($domain));
        $this->assertTrue($ned->canRead($domain->wallet()));
        $this->assertFalse($ned->canRead($reseller1));
        $this->assertFalse($ned->canRead($reseller2));
        $this->assertFalse($ned->canRead($admin));
    }

    /**
     * Test User::canUpdate() method
     */
    public function testCanUpdate(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $domain = $this->getTestDomain('kolab.org');

        // Admin
        $this->assertTrue($admin->canUpdate($admin));
        $this->assertTrue($admin->canUpdate($john));
        $this->assertTrue($admin->canUpdate($jack));
        $this->assertTrue($admin->canUpdate($reseller1));
        $this->assertTrue($admin->canUpdate($reseller2));
        $this->assertTrue($admin->canUpdate($domain));
        $this->assertTrue($admin->canUpdate($domain->wallet()));

        // Reseller - kolabnow
        $this->assertTrue($reseller1->canUpdate($john));
        $this->assertTrue($reseller1->canUpdate($jack));
        $this->assertTrue($reseller1->canUpdate($reseller1));
        $this->assertTrue($reseller1->canUpdate($domain));
        $this->assertTrue($reseller1->canUpdate($domain->wallet()));
        $this->assertFalse($reseller1->canUpdate($reseller2));
        $this->assertFalse($reseller1->canUpdate($admin));

        // Reseller - different tenant
        $this->assertTrue($reseller2->canUpdate($reseller2));
        $this->assertFalse($reseller2->canUpdate($john));
        $this->assertFalse($reseller2->canUpdate($jack));
        $this->assertFalse($reseller2->canUpdate($reseller1));
        $this->assertFalse($reseller2->canUpdate($domain));
        $this->assertFalse($reseller2->canUpdate($domain->wallet()));
        $this->assertFalse($reseller2->canUpdate($admin));

        // Normal user - account owner
        $this->assertTrue($john->canUpdate($john));
        $this->assertTrue($john->canUpdate($ned));
        $this->assertTrue($john->canUpdate($jack));
        $this->assertTrue($john->canUpdate($domain));
        $this->assertFalse($john->canUpdate($domain->wallet()));
        $this->assertFalse($john->canUpdate($reseller1));
        $this->assertFalse($john->canUpdate($reseller2));
        $this->assertFalse($john->canUpdate($admin));

        // Normal user - a non-owner and non-controller
        $this->assertTrue($jack->canUpdate($jack));
        $this->assertFalse($jack->canUpdate($john));
        $this->assertFalse($jack->canUpdate($domain));
        $this->assertFalse($jack->canUpdate($domain->wallet()));
        $this->assertFalse($jack->canUpdate($reseller1));
        $this->assertFalse($jack->canUpdate($reseller2));
        $this->assertFalse($jack->canUpdate($admin));

        // Normal user - John's wallet controller
        $this->assertTrue($ned->canUpdate($ned));
        $this->assertFalse($ned->canUpdate($john));
        $this->assertTrue($ned->canUpdate($jack));
        $this->assertTrue($ned->canUpdate($domain));
        $this->assertFalse($ned->canUpdate($domain->wallet()));
        $this->assertFalse($ned->canUpdate($reseller1));
        $this->assertFalse($ned->canUpdate($reseller2));
        $this->assertFalse($ned->canUpdate($admin));
    }

    /**
     * Test user created/creating/updated observers
     */
    public function testCreateAndUpdate(): void
    {
        Queue::fake();

        $domain = \config('app.domain');

        Tenant::find(\config('app.tenant_id'))->setSetting('pgp.enable', '0');
        $user = User::create([
            'email' => 'USER-test@' . \strtoupper($domain),
            'password' => 'test',
        ]);

        $result = User::where('email', "user-test@{$domain}")->first();

        $this->assertSame("user-test@{$domain}", $result->email);
        $this->assertSame($user->id, $result->id);
        $this->assertSame(User::STATUS_NEW, $result->status);
        $this->assertSame(0, $user->passwords()->count());

        Queue::assertPushed(CreateJob::class, 1);
        Queue::assertPushed(KeyCreateJob::class, 0);

        Queue::assertPushed(
            CreateJob::class,
            static function ($job) use ($user) {
                $userEmail = TestCase::getObjectProperty($job, 'userEmail');
                $userId = TestCase::getObjectProperty($job, 'userId');

                return $userEmail === $user->email
                    && $userId === $user->id;
            }
        );

        // Test invoking KeyCreateJob
        $this->deleteTestUser("user-test@{$domain}");

        Tenant::find(\config('app.tenant_id'))->setSetting('pgp.enable', '1');

        $user = User::create(['email' => "user-test@{$domain}", 'password' => 'test']);

        Queue::assertPushed(KeyCreateJob::class, 1);

        Queue::assertPushed(
            KeyCreateJob::class,
            static function ($job) use ($user) {
                $userEmail = TestCase::getObjectProperty($job, 'userEmail');
                $userId = TestCase::getObjectProperty($job, 'userId');

                return $userEmail === $user->email
                    && $userId === $user->id;
            }
        );

        // Update the user, test the password change
        $user->setSettings([
            'password_expiration_warning' => '2020-10-10 10:10:10',
            'password_expired' => '2020-10-20 10:10:10',
        ]);
        $oldPassword = $user->password;
        $user->password = 'test123';
        $user->save();

        $this->assertNotSame($oldPassword, $user->password);
        $this->assertSame(0, $user->passwords()->count());
        $this->assertNull($user->getSetting('password_expiration_warning'));
        $this->assertNull($user->getSetting('password_expired'));
        $this->assertMatchesRegularExpression(
            '/^' . now()->format('Y-m-d') . ' [0-9]{2}:[0-9]{2}:[0-9]{2}$/',
            $user->getSetting('password_update')
        );

        Queue::assertPushed(UpdateJob::class, 1);
        Queue::assertPushed(
            UpdateJob::class,
            static function ($job) use ($user) {
                $userEmail = TestCase::getObjectProperty($job, 'userEmail');
                $userId = TestCase::getObjectProperty($job, 'userId');

                return $userEmail === $user->email
                    && $userId === $user->id;
            }
        );

        // Update the user, test the password history
        $user->setSetting('password_policy', 'last:3');
        $oldPassword = $user->password;
        $user->password = 'test1234';
        $user->save();

        $this->assertSame(1, $user->passwords()->count());
        $this->assertSame($oldPassword, $user->passwords()->first()->password);

        $user->password = 'test12345';
        $user->save();
        $oldPassword = $user->password;
        $user->password = 'test123456';
        $user->save();

        $this->assertSame(2, $user->passwords()->count());
        $this->assertSame($oldPassword, $user->passwords()->latest()->first()->password);
    }

    /**
     * Tests for User::domains()
     */
    public function testDomains(): void
    {
        $user = $this->getTestUser('john@kolab.org');

        $domain = $this->getTestDomain('useraccount.com', [
            'status' => Domain::STATUS_NEW | Domain::STATUS_ACTIVE,
            'type' => Domain::TYPE_PUBLIC,
        ]);

        $domains = $user->domains()->pluck('namespace')->all();
        $this->assertContains($domain->namespace, $domains);
        $this->assertContains('kolab.org', $domains);

        $domains = $user->domains(false, false)->pluck('namespace')->all();
        $this->assertSame(['kolab.org'], $domains);

        // Jack is not the wallet controller, so for him the list should not
        // include John's domains, kolab.org specifically
        $user = $this->getTestUser('jack@kolab.org');

        $domains = $user->domains()->pluck('namespace')->all();
        $this->assertContains($domain->namespace, $domains);
        $this->assertNotContains('kolab.org', $domains);

        // Public domains of other tenants should not be returned
        $tenant = Tenant::where('id', '!=', \config('app.tenant_id'))->first();
        $domain->tenant_id = $tenant->id;
        $domain->save();

        $domains = $user->domains()->pluck('namespace')->all();
        $this->assertNotContains($domain->namespace, $domains);

        // An account in a public domain
        $user = $this->getTestUser('user-test@' . \config('app.domain'));

        $domains = $user->domains()->pluck('namespace')->all();
        $this->assertContains(\config('app.domain'), $domains);

        $domains = $user->domains(true, false)->pluck('namespace')->all();
        $this->assertSame([], $domains);
    }

    /**
     * Test User::getConfig() and setConfig() methods
     */
    public function testConfigTrait(): void
    {
        $user = $this->getTestUser('UserAccountA@UserAccount.com');
        $user->setSetting('greylist_enabled', null);
        $user->setSetting('guam_enabled', null);
        $user->setSetting('password_policy', null);
        $user->setSetting('max_password_age', null);
        $user->setSetting('limit_geo', null);
        $user->setSetting('externalsender_policy_domains', null);

        // greylist_enabled
        $this->assertNull($user->getConfig()['greylist_enabled']);

        $result = $user->setConfig(['greylist_enabled' => false, 'unknown' => false]);

        $this->assertSame(['unknown' => "The requested configuration parameter is not supported."], $result);
        $this->assertFalse($user->getConfig()['greylist_enabled']);
        $this->assertSame('false', $user->getSetting('greylist_enabled'));

        $result = $user->setConfig(['greylist_enabled' => true]);

        $this->assertSame([], $result);
        $this->assertTrue($user->getConfig()['greylist_enabled']);
        $this->assertSame('true', $user->getSetting('greylist_enabled'));

        // guam_enabled
        $this->assertFalse($user->getConfig()['guam_enabled']);

        $result = $user->setConfig(['guam_enabled' => false]);

        $this->assertSame([], $result);
        $this->assertFalse($user->getConfig()['guam_enabled']);
        $this->assertNull($user->getSetting('guam_enabled'));

        $result = $user->setConfig(['guam_enabled' => true]);

        $this->assertSame([], $result);
        $this->assertTrue($user->getConfig()['guam_enabled']);
        $this->assertSame('true', $user->getSetting('guam_enabled'));

        // max_apssword_age
        $this->assertNull($user->getConfig()['max_password_age']);

        $result = $user->setConfig(['max_password_age' => -1]);

        $this->assertSame([], $result);
        $this->assertNull($user->getConfig()['max_password_age']);
        $this->assertNull($user->getSetting('max_password_age'));

        $result = $user->setConfig(['max_password_age' => 12]);

        $this->assertSame([], $result);
        $this->assertSame('12', $user->getConfig()['max_password_age']);
        $this->assertSame('12', $user->getSetting('max_password_age'));

        // password_policy
        $result = $user->setConfig(['password_policy' => true]);

        $this->assertSame(['password_policy' => "Specified password policy is invalid."], $result);
        $this->assertNull($user->getConfig()['password_policy']);
        $this->assertNull($user->getSetting('password_policy'));

        $result = $user->setConfig(['password_policy' => 'min:-1']);

        $this->assertSame(['password_policy' => "Specified password policy is invalid."], $result);

        $result = $user->setConfig(['password_policy' => 'min:-1']);

        $this->assertSame(['password_policy' => "Specified password policy is invalid."], $result);

        $result = $user->setConfig(['password_policy' => 'min:10,unknown']);

        $this->assertSame(['password_policy' => "Specified password policy is invalid."], $result);

        \config(['app.password_policy' => 'min:5,max:100']);
        $result = $user->setConfig(['password_policy' => 'min:4,max:255']);

        $this->assertSame(['password_policy' => "Minimum password length cannot be less than 5."], $result);

        \config(['app.password_policy' => 'min:5,max:100']);
        $result = $user->setConfig(['password_policy' => 'min:10,max:255']);

        $this->assertSame(['password_policy' => "Maximum password length cannot be more than 100."], $result);

        \config(['app.password_policy' => 'min:5,max:255']);
        $result = $user->setConfig(['password_policy' => 'min:10,max:255']);

        $this->assertSame([], $result);
        $this->assertSame('min:10,max:255', $user->getConfig()['password_policy']);
        $this->assertSame('min:10,max:255', $user->getSetting('password_policy'));

        // externalsender_policy_domains
        $result = $user->setConfig(['externalsender_policy_domains' => []]);
        $this->assertSame([], $user->getConfig()['externalsender_policy_domains']);

        $result = $user->setConfig(['externalsender_policy_domains' => ['aa=a']]);
        $err = "Specified configuration is invalid. Expected a list domain names.";
        $this->assertSame(['externalsender_policy_domains' => $err], $result);
        $this->assertSame([], $user->getConfig()['externalsender_policy_domains']);

        $result = $user->setConfig(['externalsender_policy_domains' => ['domain.tld', 'Test.com']]);
        $this->assertSame(['domain.tld', 'test.com'], $user->getConfig()['externalsender_policy_domains']);

        // limit_geo
        $this->assertSame([], $user->getConfig()['limit_geo']);

        $result = $user->setConfig(['limit_geo' => '']);

        $err = "Specified configuration is invalid. Expected a list of two-letter country codes.";
        $this->assertSame(['limit_geo' => $err], $result);
        $this->assertNull($user->getSetting('limit_geo'));

        $result = $user->setConfig(['limit_geo' => ['usa']]);

        $this->assertSame(['limit_geo' => $err], $result);
        $this->assertNull($user->getSetting('limit_geo'));

        $result = $user->setConfig(['limit_geo' => []]);

        $this->assertSame([], $result);
        $this->assertNull($user->getSetting('limit_geo'));

        $result = $user->setConfig(['limit_geo' => ['US', 'ru']]);

        $err = 'Specified configuration is invalid. Missing country of the current connection (CH).';
        $this->assertSame(['limit_geo' => $err], $result);

        $result = $user->setConfig(['limit_geo' => ['US', 'ch']]);

        $this->assertSame([], $result);
        $this->assertSame(['US', 'CH'], $user->getConfig()['limit_geo']);
        $this->assertSame('["US","CH"]', $user->getSetting('limit_geo'));

        // Test getting account owner policies
        $userB = $this->getTestUser('UserAccountB@UserAccount.com');
        $package_kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $user->assignPackage($package_kolab, $userB);
        $user->setConfig(['greylist_policy' => false]);

        $this->assertTrue($userB->getConfig()['greylist_policy']);
        $this->assertFalse($userB->getConfig(true)['greylist_policy']);
    }

    /**
     * Test user account degradation and un-degradation
     */
    public function testDegradeAndUndegrade(): void
    {
        $this->fakeQueueReset();

        // Test an account with users, domain
        $userA = $this->getTestUser('UserAccountA@UserAccount.com');
        $userB = $this->getTestUser('UserAccountB@UserAccount.com');
        $package_kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $package_domain = Package::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $domain = $this->getTestDomain('UserAccount.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_HOSTED,
        ]);
        $userA->assignPackage($package_kolab);
        $domain->assignPackage($package_domain, $userA);
        $userA->assignPackage($package_kolab, $userB);

        $entitlementsA = Entitlement::where('entitleable_id', $userA->id);
        $entitlementsB = Entitlement::where('entitleable_id', $userB->id);
        $entitlementsDomain = Entitlement::where('entitleable_id', $domain->id);

        $yesterday = Carbon::now()->subDays(1);

        $this->backdateEntitlements($entitlementsA->get(), $yesterday, Carbon::now()->subMonthsWithoutOverflow(1));
        $this->backdateEntitlements($entitlementsB->get(), $yesterday, Carbon::now()->subMonthsWithoutOverflow(1));

        $wallet = $userA->wallets->first();

        $this->assertSame(7, $entitlementsA->count());
        $this->assertSame(7, $entitlementsB->count());
        $this->assertSame(7, $entitlementsA->whereDate('updated_at', $yesterday->toDateString())->count());
        $this->assertSame(7, $entitlementsB->whereDate('updated_at', $yesterday->toDateString())->count());
        $this->assertSame(0, $wallet->balance);

        $this->fakeQueueReset();

        // Degrade the account/wallet owner
        $userA->degrade();

        $entitlementsA = Entitlement::where('entitleable_id', $userA->id);
        $entitlementsB = Entitlement::where('entitleable_id', $userB->id);

        $this->assertTrue($userA->fresh()->isDegraded());
        $this->assertTrue($userA->fresh()->isDegraded(true));
        $this->assertFalse($userB->fresh()->isDegraded());
        $this->assertTrue($userB->fresh()->isDegraded(true));

        $balance = $wallet->fresh()->balance;
        $this->assertTrue($balance < 0);
        $this->assertSame(7, $entitlementsA->whereDate('updated_at', Carbon::now()->toDateString())->count());
        $this->assertSame(7, $entitlementsB->whereDate('updated_at', Carbon::now()->toDateString())->count());

        // Expect one update job for every user
        // @phpstan-ignore-next-line
        $userIds = Queue::pushed(UpdateJob::class)->map(static function ($job) {
            return TestCase::getObjectProperty($job, 'userId');
        })->all();

        $this->assertSame([$userA->id, $userB->id], $userIds);

        // Un-Degrade the account/wallet owner

        $entitlementsA = Entitlement::where('entitleable_id', $userA->id);
        $entitlementsB = Entitlement::where('entitleable_id', $userB->id);

        $yesterday = Carbon::now()->subDays(1);

        $this->backdateEntitlements($entitlementsA->get(), $yesterday, Carbon::now()->subMonthsWithoutOverflow(1));
        $this->backdateEntitlements($entitlementsB->get(), $yesterday, Carbon::now()->subMonthsWithoutOverflow(1));

        $this->fakeQueueReset();

        $userA->undegrade();

        $this->assertFalse($userA->fresh()->isDegraded());
        $this->assertFalse($userA->fresh()->isDegraded(true));
        $this->assertFalse($userB->fresh()->isDegraded());
        $this->assertFalse($userB->fresh()->isDegraded(true));

        // Expect no balance change, degraded account entitlements are free
        $this->assertSame($balance, $wallet->fresh()->balance);
        $this->assertSame(7, $entitlementsA->whereDate('updated_at', Carbon::now()->toDateString())->count());
        $this->assertSame(7, $entitlementsB->whereDate('updated_at', Carbon::now()->toDateString())->count());

        // Expect one update job for every user
        // @phpstan-ignore-next-line
        $userIds = Queue::pushed(UpdateJob::class)->map(static function ($job) {
            return TestCase::getObjectProperty($job, 'userId');
        })->all();

        $this->assertSame([$userA->id, $userB->id], $userIds);
    }

    /**
     * Test user deletion
     */
    public function testDelete(): void
    {
        Queue::fake();

        $user = $this->getTestUser('user-test@' . \config('app.domain'));
        $package = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $user->assignPackage($package);

        $id = $user->id;

        $this->assertCount(7, $user->entitlements()->get());

        Queue::fake();

        $user->delete();

        $this->assertCount(0, $user->entitlements()->get());
        $this->assertTrue($user->fresh()->trashed());
        $this->assertFalse($user->fresh()->isDeleted());

        Queue::assertPushed(DeleteJob::class, 1);
        Queue::assertPushed(UpdateJob::class, 0);

        // Delete the user for real
        $job = new DeleteJob($id);
        $job->handle();

        $this->assertTrue(User::withTrashed()->where('id', $id)->first()->isDeleted());

        Queue::fake();

        $user->forceDelete();

        $this->assertCount(0, User::withTrashed()->where('id', $id)->get());

        Queue::assertPushed(DeleteJob::class, 0);
        Queue::assertPushed(UpdateJob::class, 0);

        // Test an account with users, domain, and group, and resource
        $userA = $this->getTestUser('UserAccountA@UserAccount.com');
        $userB = $this->getTestUser('UserAccountB@UserAccount.com');
        $userC = $this->getTestUser('UserAccountC@UserAccount.com');
        $package_kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $package_domain = Package::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $domain = $this->getTestDomain('UserAccount.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_HOSTED,
        ]);
        $userA->assignPackage($package_kolab);
        $domain->assignPackage($package_domain, $userA);
        $userA->assignPackage($package_kolab, $userB);
        $userA->assignPackage($package_kolab, $userC);
        $group = $this->getTestGroup('test-group@UserAccount.com');
        $group->assignToWallet($userA->wallets->first());
        $resource = $this->getTestResource('test-resource@UserAccount.com', ['name' => 'test']);
        $resource->assignToWallet($userA->wallets->first());
        $folder = $this->getTestSharedFolder('test-folder@UserAccount.com', ['name' => 'test']);
        $folder->assignToWallet($userA->wallets->first());

        $entitlementsA = Entitlement::where('entitleable_id', $userA->id);
        $entitlementsB = Entitlement::where('entitleable_id', $userB->id);
        $entitlementsC = Entitlement::where('entitleable_id', $userC->id);
        $entitlementsDomain = Entitlement::where('entitleable_id', $domain->id);
        $entitlementsGroup = Entitlement::where('entitleable_id', $group->id);
        $entitlementsResource = Entitlement::where('entitleable_id', $resource->id);
        $entitlementsFolder = Entitlement::where('entitleable_id', $folder->id);

        $this->assertSame(7, $entitlementsA->count());
        $this->assertSame(7, $entitlementsB->count());
        $this->assertSame(7, $entitlementsC->count());
        $this->assertSame(1, $entitlementsDomain->count());
        $this->assertSame(1, $entitlementsGroup->count());
        $this->assertSame(1, $entitlementsResource->count());
        $this->assertSame(1, $entitlementsFolder->count());

        // Delete non-controller user
        $userC->delete();

        $this->assertTrue($userC->fresh()->trashed());
        $this->assertFalse($userC->fresh()->isDeleted());
        $this->assertSame(0, $entitlementsC->count());

        // Delete the controller (and expect "sub"-users to be deleted too)
        $userA->delete();

        $this->assertSame(0, $entitlementsA->count());
        $this->assertSame(0, $entitlementsB->count());
        $this->assertSame(0, $entitlementsDomain->count());
        $this->assertSame(0, $entitlementsGroup->count());
        $this->assertSame(0, $entitlementsResource->count());
        $this->assertSame(0, $entitlementsFolder->count());
        $this->assertSame(7, $entitlementsA->withTrashed()->count());
        $this->assertSame(7, $entitlementsB->withTrashed()->count());
        $this->assertSame(7, $entitlementsC->withTrashed()->count());
        $this->assertSame(1, $entitlementsDomain->withTrashed()->count());
        $this->assertSame(1, $entitlementsGroup->withTrashed()->count());
        $this->assertSame(1, $entitlementsResource->withTrashed()->count());
        $this->assertSame(1, $entitlementsFolder->withTrashed()->count());
        $this->assertTrue($userA->fresh()->trashed());
        $this->assertTrue($userB->fresh()->trashed());
        $this->assertTrue($domain->fresh()->trashed());
        $this->assertTrue($group->fresh()->trashed());
        $this->assertTrue($resource->fresh()->trashed());
        $this->assertTrue($folder->fresh()->trashed());
        $this->assertFalse($userA->isDeleted());
        $this->assertFalse($userB->isDeleted());
        $this->assertFalse($domain->isDeleted());
        $this->assertFalse($group->isDeleted());
        $this->assertFalse($resource->isDeleted());
        $this->assertFalse($folder->isDeleted());

        $userA->forceDelete();

        $all_entitlements = Entitlement::where('wallet_id', $userA->wallets->first()->id);
        $transactions = Transaction::where('object_id', $userA->wallets->first()->id);

        $this->assertSame(0, $all_entitlements->withTrashed()->count());
        $this->assertSame(0, $transactions->count());
        $this->assertCount(0, User::withTrashed()->where('id', $userA->id)->get());
        $this->assertCount(0, User::withTrashed()->where('id', $userB->id)->get());
        $this->assertCount(0, User::withTrashed()->where('id', $userC->id)->get());
        $this->assertCount(0, Domain::withTrashed()->where('id', $domain->id)->get());
        $this->assertCount(0, Group::withTrashed()->where('id', $group->id)->get());
        $this->assertCount(0, Resource::withTrashed()->where('id', $resource->id)->get());
        $this->assertCount(0, SharedFolder::withTrashed()->where('id', $folder->id)->get());
    }

    /**
     * Test eventlog on user deletion
     */
    public function testDeleteAndEventLog(): void
    {
        Queue::fake();

        $user = $this->getTestUser('user-test@' . \config('app.domain'));

        EventLog::createFor($user, EventLog::TYPE_SUSPENDED, 'test');

        $user->delete();

        $this->assertCount(1, EventLog::where('object_id', $user->id)->where('object_type', User::class)->get());

        $user->forceDelete();

        $this->assertCount(0, EventLog::where('object_id', $user->id)->where('object_type', User::class)->get());
    }

    /**
     * Test user deletion vs. group membership
     *
     * The first Queue::assertPushed is sometimes 1 and sometimes 2
     *
     * @group skipci
     */
    public function testDeleteAndGroups(): void
    {
        Queue::fake();

        $package_kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $userA = $this->getTestUser('UserAccountA@UserAccount.com');
        $userB = $this->getTestUser('UserAccountB@UserAccount.com');
        $userA->assignPackage($package_kolab, $userB);
        $group = $this->getTestGroup('test-group@UserAccount.com');
        $group->members = ['test@gmail.com', $userB->email];
        $group->assignToWallet($userA->wallets->first());
        $group->save();

        Queue::assertPushed(\App\Jobs\Group\UpdateJob::class, 1);

        $userGroups = $userA->groups()->get();
        $this->assertSame(1, $userGroups->count());
        $this->assertSame($group->id, $userGroups->first()->id);

        $userB->delete();

        $this->assertSame(['test@gmail.com'], $group->fresh()->members);

        // Twice, one for save() and one for delete() above
        Queue::assertPushed(\App\Jobs\Group\UpdateJob::class, 2);
    }

    /**
     * Test user deletion regarding delegation relations
     */
    public function testDeleteWithDelegation(): void
    {
        Queue::fake();

        // Test removing delegatee
        $userA = $this->getTestUser('UserAccountA@UserAccount.com');
        $userB = $this->getTestUser('UserAccountB@UserAccount.com');

        $delegation = new Delegation();
        $delegation->user_id = $userA->id;
        $delegation->delegatee_id = $userB->id;
        $delegation->save();

        $userB->delete();

        $this->assertNull(Delegation::find($delegation->id));

        Queue::assertPushed(\App\Jobs\User\Delegation\DeleteJob::class, 1);
        Queue::assertPushed(
            \App\Jobs\User\Delegation\DeleteJob::class,
            static function ($job) use ($userA, $userB) {
                $delegator = TestCase::getObjectProperty($job, 'delegatorEmail');
                $delegatee = TestCase::getObjectProperty($job, 'delegateeEmail');
                return $delegator === $userA->email && $delegatee === $userB->email;
            }
        );

        $userB->deleted_at = null;
        $userB->save();

        Queue::fake();

        // Test removing delegator
        $delegation = new Delegation();
        $delegation->user_id = $userA->id;
        $delegation->delegatee_id = $userB->id;
        $delegation->save();

        $userA->delete();

        $this->assertNull(Delegation::find($delegation->id));

        Queue::assertPushed(\App\Jobs\User\Delegation\DeleteJob::class, 1);
        Queue::assertPushed(
            \App\Jobs\User\Delegation\DeleteJob::class,
            static function ($job) use ($userA, $userB) {
                $delegator = TestCase::getObjectProperty($job, 'delegatorEmail');
                $delegatee = TestCase::getObjectProperty($job, 'delegateeEmail');
                return $delegator === $userA->email && $delegatee === $userB->email;
            }
        );
    }

    /**
     * Test user deletion with PGP/WOAT enabled
     */
    public function testDeleteWithPGP(): void
    {
        Queue::fake();

        // Test with PGP disabled
        $user = $this->getTestUser('user-test@' . \config('app.domain'));

        $user->tenant->setSetting('pgp.enable', 0);
        $user->delete();

        Queue::assertPushed(KeyDeleteJob::class, 0);

        // Test with PGP enabled
        $this->deleteTestUser('user-test@' . \config('app.domain'));
        $user = $this->getTestUser('user-test@' . \config('app.domain'));

        $user->tenant->setSetting('pgp.enable', 1);
        $user->delete();
        $user->tenant->setSetting('pgp.enable', 0);

        Queue::assertPushed(KeyDeleteJob::class, 1);
        Queue::assertPushed(
            KeyDeleteJob::class,
            static function ($job) use ($user) {
                $userId = TestCase::getObjectProperty($job, 'userId');
                $userEmail = TestCase::getObjectProperty($job, 'userEmail');
                return $userId == $user->id && $userEmail === $user->email;
            }
        );
    }

    /**
     * Test user deletion vs. rooms
     */
    public function testDeleteWithRooms(): void
    {
        $this->markTestIncomplete();
    }

    /**
     * Tests for User::aliasExists()
     */
    public function testAliasExists(): void
    {
        $this->assertTrue(User::aliasExists('jack.daniels@kolab.org'));

        $this->assertFalse(User::aliasExists('j.daniels@kolab.org'));
        $this->assertFalse(User::aliasExists('john@kolab.org'));
    }

    /**
     * Tests for User::emailExists()
     */
    public function testEmailExists(): void
    {
        $this->assertFalse(User::emailExists('jack.daniels@kolab.org'));
        $this->assertFalse(User::emailExists('j.daniels@kolab.org'));

        $this->assertTrue(User::emailExists('john@kolab.org'));
        $user = User::emailExists('john@kolab.org', true);
        $this->assertSame('john@kolab.org', $user->email);
    }

    /**
     * Tests for User::findByEmail()
     */
    public function testFindByEmail(): void
    {
        $user = $this->getTestUser('john@kolab.org');

        $result = User::findByEmail('john');
        $this->assertNull($result);

        $result = User::findByEmail('non-existing@email.com');
        $this->assertNull($result);

        $result = User::findByEmail('john@kolab.org');
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user->id, $result->id);

        // Use an alias
        $result = User::findByEmail('john.doe@kolab.org');
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($user->id, $result->id);

        Queue::fake();

        // A case where two users have the same alias
        $ned = $this->getTestUser('ned@kolab.org');
        $ned->setAliases(['joe.monster@kolab.org']);
        $result = User::findByEmail('joe.monster@kolab.org');
        $this->assertNull($result);
        $ned->setAliases([]);

        // TODO: searching by external email (setting)
        $this->markTestIncomplete();
    }

    /**
     * Test User::hasSku() and countEntitlementsBySku() methods
     */
    public function testHasSku(): void
    {
        $john = $this->getTestUser('john@kolab.org');

        $this->assertTrue($john->hasSku('mailbox'));
        $this->assertTrue($john->hasSku('storage'));
        $this->assertFalse($john->hasSku('beta'));
        $this->assertFalse($john->hasSku('unknown'));

        $this->assertSame(0, $john->countEntitlementsBySku('unknown'));
        $this->assertSame(0, $john->countEntitlementsBySku('2fa'));
        $this->assertSame(1, $john->countEntitlementsBySku('mailbox'));
        $this->assertSame(5, $john->countEntitlementsBySku('storage'));
    }

    /**
     * Test User::name()
     */
    public function testName(): void
    {
        Queue::fake();

        $user = $this->getTestUser('user-test@' . \config('app.domain'));

        $this->assertSame('', $user->name());
        $this->assertSame($user->tenant->title . ' User', $user->name(true));

        $user->setSetting('first_name', 'First');

        $this->assertSame('First', $user->name());
        $this->assertSame('First', $user->name(true));

        $user->setSetting('last_name', 'Last');

        $this->assertSame('First Last', $user->name());
        $this->assertSame('First Last', $user->name(true));
    }

    /**
     * Test resources() method
     */
    public function testResources(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');

        $resources = $john->resources()->orderBy('email')->get();

        $this->assertSame(2, $resources->count());
        $this->assertSame('resource-test1@kolab.org', $resources[0]->email);
        $this->assertSame('resource-test2@kolab.org', $resources[1]->email);

        $resources = $ned->resources()->orderBy('email')->get();

        $this->assertSame(2, $resources->count());
        $this->assertSame('resource-test1@kolab.org', $resources[0]->email);
        $this->assertSame('resource-test2@kolab.org', $resources[1]->email);

        $resources = $jack->resources()->get();

        $this->assertSame(0, $resources->count());
    }

    /**
     * Test sharedFolders() method
     */
    public function testSharedFolders(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');

        $folders = $john->sharedFolders()->orderBy('email')->get();

        $this->assertSame(2, $folders->count());
        $this->assertSame('folder-contact@kolab.org', $folders[0]->email);
        $this->assertSame('folder-event@kolab.org', $folders[1]->email);

        $folders = $ned->sharedFolders()->orderBy('email')->get();

        $this->assertSame(2, $folders->count());
        $this->assertSame('folder-contact@kolab.org', $folders[0]->email);
        $this->assertSame('folder-event@kolab.org', $folders[1]->email);

        $folders = $jack->sharedFolders()->get();

        $this->assertSame(0, $folders->count());
    }

    /**
     * Test user restoring
     */
    public function testRestore(): void
    {
        $this->fakeQueueReset();

        // Test an account with users and domain
        $userA = $this->getTestUser('UserAccountA@UserAccount.com', [
            'status' => User::STATUS_LDAP_READY | User::STATUS_IMAP_READY | User::STATUS_SUSPENDED,
        ]);
        $userB = $this->getTestUser('UserAccountB@UserAccount.com');
        $package_kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $package_domain = Package::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $domainA = $this->getTestDomain('UserAccount.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_HOSTED,
        ]);
        $domainB = $this->getTestDomain('UserAccountAdd.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_HOSTED,
        ]);
        $userA->assignPackage($package_kolab);
        $domainA->assignPackage($package_domain, $userA);
        $domainB->assignPackage($package_domain, $userA);
        $userA->assignPackage($package_kolab, $userB);

        $storage_sku = Sku::withEnvTenantContext()->where('title', 'storage')->first();
        $now = Carbon::now();
        $wallet_id = $userA->wallets->first()->id;

        // add an extra storage entitlement
        $ent1 = Entitlement::create([
            'wallet_id' => $wallet_id,
            'sku_id' => $storage_sku->id,
            'cost' => 0,
            'entitleable_id' => $userA->id,
            'entitleable_type' => User::class,
        ]);

        $entitlementsA = Entitlement::where('entitleable_id', $userA->id);
        $entitlementsB = Entitlement::where('entitleable_id', $userB->id);
        $entitlementsDomain = Entitlement::where('entitleable_id', $domainA->id);

        // First delete the user
        $userA->delete();

        $this->assertSame(0, $entitlementsA->count());
        $this->assertSame(0, $entitlementsB->count());
        $this->assertSame(0, $entitlementsDomain->count());
        $this->assertTrue($userA->fresh()->trashed());
        $this->assertTrue($userB->fresh()->trashed());
        $this->assertTrue($domainA->fresh()->trashed());
        $this->assertTrue($domainB->fresh()->trashed());
        $this->assertFalse($userA->isDeleted());
        $this->assertFalse($userB->isDeleted());
        $this->assertFalse($domainA->isDeleted());

        // Backdate one storage entitlement (it's not expected to be restored)
        Entitlement::withTrashed()->where('id', $ent1->id)
            ->update(['deleted_at' => $now->copy()->subMinutes(2)]);

        // Backdate entitlements to assert that they were restored with proper updated_at timestamp
        Entitlement::withTrashed()->where('wallet_id', $wallet_id)
            ->update(['updated_at' => $now->subMinutes(10)]);

        $this->fakeQueueReset();

        // Then restore it
        $userA->restore();
        $userA->refresh();

        $this->assertFalse($userA->trashed());
        $this->assertFalse($userA->isDeleted());
        $this->assertFalse($userA->isSuspended());
        $this->assertFalse($userA->isLdapReady());
        $this->assertFalse($userA->isImapReady());
        $this->assertFalse($userA->isActive());
        $this->assertTrue($userA->isNew());

        $this->assertTrue($userB->fresh()->trashed());
        $this->assertTrue($domainB->fresh()->trashed());
        $this->assertFalse($domainA->fresh()->trashed());

        // Assert entitlements
        $this->assertSame(7, $entitlementsA->count()); // mailbox + groupware + 5 x storage
        $this->assertTrue($ent1->fresh()->trashed());
        $entitlementsA->get()->each(function ($ent) {
            $this->assertTrue($ent->updated_at->greaterThan(Carbon::now()->subSeconds(5)));
        });

        // We expect only CreateJob + UpdateJob pair for both user and domain.
        // Because how Illuminate/Database/Eloquent/SoftDeletes::restore() method
        // is implemented we cannot skip the UpdateJob in any way.
        // I don't want to overwrite this method, the extra job shouldn't do any harm.
        $this->assertCount(4, Queue::pushedJobs()); // @phpstan-ignore-line
        Queue::assertPushed(\App\Jobs\Domain\UpdateJob::class, 1);
        Queue::assertPushed(\App\Jobs\Domain\CreateJob::class, 1);
        Queue::assertPushed(UpdateJob::class, 1);
        Queue::assertPushed(CreateJob::class, 1);
        Queue::assertPushed(
            CreateJob::class,
            static function ($job) use ($userA) {
                return $userA->id === TestCase::getObjectProperty($job, 'userId');
            }
        );
    }

    /**
     * Test user account restrict() and unrestrict()
     */
    public function testRestrictAndUnrestrict(): void
    {
        $this->fakeQueueReset();

        // Test an account with users, domain
        $user = $this->getTestUser('UserAccountA@UserAccount.com');
        $userB = $this->getTestUser('UserAccountB@UserAccount.com');
        $package_kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $package_domain = Package::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $domain = $this->getTestDomain('UserAccount.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_HOSTED,
        ]);
        $user->assignPackage($package_kolab);
        $domain->assignPackage($package_domain, $user);
        $user->assignPackage($package_kolab, $userB);

        $this->assertFalse($user->isRestricted());
        $this->assertFalse($userB->isRestricted());

        $user->restrict();

        $this->assertTrue($user->fresh()->isRestricted());
        $this->assertFalse($userB->fresh()->isRestricted());

        Queue::assertPushed(
            UpdateJob::class,
            static function ($job) use ($user) {
                return TestCase::getObjectProperty($job, 'userId') == $user->id;
            }
        );

        $userB->restrict();
        $this->assertTrue($userB->fresh()->isRestricted());

        $this->fakeQueueReset();

        $user->refresh();
        $user->unrestrict();

        $this->assertFalse($user->fresh()->isRestricted());
        $this->assertTrue($userB->fresh()->isRestricted());

        Queue::assertPushed(
            UpdateJob::class,
            static function ($job) use ($user) {
                return TestCase::getObjectProperty($job, 'userId') == $user->id;
            }
        );

        $this->fakeQueueReset();

        $user->unrestrict(true);

        $this->assertFalse($user->fresh()->isRestricted());
        $this->assertFalse($userB->fresh()->isRestricted());

        Queue::assertPushed(
            UpdateJob::class,
            static function ($job) use ($userB) {
                return TestCase::getObjectProperty($job, 'userId') == $userB->id;
            }
        );
    }

    /**
     * Tests for AliasesTrait::setAliases()
     */
    public function testSetAliases(): void
    {
        $this->fakeQueueReset();

        $user = $this->getTestUser('UserAccountA@UserAccount.com');
        $domain = $this->getTestDomain('UserAccount.com', [
            'status' => Domain::STATUS_NEW,
            'type' => Domain::TYPE_HOSTED,
        ]);

        $this->assertCount(0, $user->aliases->all());

        $user->tenant->setSetting('pgp.enable', 1);

        // Add an alias
        $user->setAliases(['UserAlias1@UserAccount.com']);

        Queue::assertPushed(UpdateJob::class, 1);
        Queue::assertPushed(KeyCreateJob::class, 1);

        $user->tenant->setSetting('pgp.enable', 0);

        $aliases = $user->aliases()->get();
        $this->assertCount(1, $aliases);
        $this->assertSame('useralias1@useraccount.com', $aliases[0]['alias']);

        // Add another alias
        $this->fakeQueueReset();
        $user->setAliases(['UserAlias1@UserAccount.com', 'UserAlias2@UserAccount.com']);

        Queue::assertPushed(UpdateJob::class, 1);
        Queue::assertPushed(KeyCreateJob::class, 0);

        $aliases = $user->aliases()->orderBy('alias')->get();
        $this->assertCount(2, $aliases);
        $this->assertSame('useralias1@useraccount.com', $aliases[0]->alias);
        $this->assertSame('useralias2@useraccount.com', $aliases[1]->alias);

        $user->tenant->setSetting('pgp.enable', 1);

        $userB = $this->getTestUser('UserAccountB@UserAccount.com');
        $delegation = Delegation::create(['user_id' => $user->id, 'delegatee_id' => $userB->id]);

        // Remove an alias
        $this->fakeQueueReset();
        $user->setAliases(['UserAlias1@UserAccount.com']);

        $user->tenant->setSetting('pgp.enable', 0);

        Queue::assertPushed(UpdateJob::class, 1);
        Queue::assertPushed(KeyDeleteJob::class, 1);
        Queue::assertPushed(
            KeyDeleteJob::class,
            static function ($job) use ($user) {
                $userId = TestCase::getObjectProperty($job, 'userId');
                $userEmail = TestCase::getObjectProperty($job, 'userEmail');
                return $userId == $user->id && $userEmail === 'useralias2@useraccount.com';
            }
        );
        Queue::assertPushed(\App\Jobs\User\Delegation\UserRefreshJob::class, 1);
        Queue::assertPushed(
            \App\Jobs\User\Delegation\UserRefreshJob::class,
            function ($job) use ($userB) {
                $userId = TestCase::getObjectProperty($job, 'userId');
                $userEmail = TestCase::getObjectProperty($job, 'userEmail');
                return $userId == $userB->id && $userEmail === $userB->email;
            }
        );

        $aliases = $user->aliases()->get();
        $this->assertCount(1, $aliases);
        $this->assertSame('useralias1@useraccount.com', $aliases[0]['alias']);

        $delegation->delete();

        // Remove all aliases
        $this->fakeQueueReset();
        $user->setAliases([]);

        Queue::assertPushed(UpdateJob::class, 1);
        Queue::assertPushed(\App\Jobs\User\Delegation\UserRefreshJob::class, 0);

        $this->assertCount(0, $user->aliases()->get());
    }

    /**
     * Tests for suspendAccount()
     */
    public function testSuspendAccount(): void
    {
        $user = $this->getTestUser('UserAccountA@UserAccount.com');
        $wallet = $user->wallets()->first();

        // No entitlements, expect the wallet owner to be suspended anyway
        $user->suspendAccount();

        $this->assertTrue($user->fresh()->isSuspended());

        // Add entitlements and more suspendable objects into the wallet
        $user->unsuspend();
        $mailbox_sku = Sku::withEnvTenantContext()->where('title', 'mailbox')->first();
        $domain_sku = Sku::withEnvTenantContext()->where('title', 'domain-hosting')->first();
        $group_sku = Sku::withEnvTenantContext()->where('title', 'group')->first();
        $resource_sku = Sku::withEnvTenantContext()->where('title', 'resource')->first();
        $userB = $this->getTestUser('UserAccountB@UserAccount.com');
        $userB->assignSku($mailbox_sku, 1, $wallet);
        $domain = $this->getTestDomain('UserAccount.com', ['type' => Domain::TYPE_PUBLIC]);
        $domain->assignSku($domain_sku, 1, $wallet);
        $group = $this->getTestGroup('test-group@UserAccount.com');
        $group->assignSku($group_sku, 1, $wallet);
        $resource = $this->getTestResource('test-resource@UserAccount.com');
        $resource->assignSku($resource_sku, 1, $wallet);

        $this->assertFalse($user->isSuspended());
        $this->assertFalse($userB->isSuspended());
        $this->assertFalse($domain->isSuspended());
        $this->assertFalse($group->isSuspended());
        $this->assertFalse($resource->isSuspended());

        $user->suspendAccount();

        $this->assertTrue($user->fresh()->isSuspended());
        $this->assertTrue($userB->fresh()->isSuspended());
        $this->assertTrue($domain->fresh()->isSuspended());
        $this->assertTrue($group->fresh()->isSuspended());
        $this->assertFalse($resource->fresh()->isSuspended());
    }

    /**
     * Tests for UserSettingsTrait::setSettings() and getSetting() and getSettings()
     */
    public function testUserSettings(): void
    {
        $this->fakeQueueReset();

        $user = $this->getTestUser('UserAccountA@UserAccount.com');

        Queue::assertPushed(UpdateJob::class, 0);

        // Test default settings
        // Note: Technicly this tests UserObserver::created() behavior
        $all_settings = $user->settings()->orderBy('key')->get();
        $this->assertCount(2, $all_settings);
        $this->assertSame('country', $all_settings[0]->key);
        $this->assertSame('CH', $all_settings[0]->value);
        $this->assertSame('currency', $all_settings[1]->key);
        $this->assertSame('CHF', $all_settings[1]->value);

        // Add a setting
        $user->setSetting('first_name', 'Firstname');

        if (\config('app.with_ldap')) {
            Queue::assertPushed(UpdateJob::class, 1);
        }

        // Note: We test both current user as well as fresh user object
        //       to make sure cache works as expected
        $this->assertSame('Firstname', $user->getSetting('first_name'));
        $this->assertSame('Firstname', $user->fresh()->getSetting('first_name'));

        // Update a setting
        $this->fakeQueueReset();
        $user->setSetting('first_name', 'Firstname1');

        if (\config('app.with_ldap')) {
            Queue::assertPushed(UpdateJob::class, 1);
        }

        // Note: We test both current user as well as fresh user object
        //       to make sure cache works as expected
        $this->assertSame('Firstname1', $user->getSetting('first_name'));
        $this->assertSame('Firstname1', $user->fresh()->getSetting('first_name'));

        // Delete a setting (null)
        $this->fakeQueueReset();
        $user->setSetting('first_name', null);

        if (\config('app.with_ldap')) {
            Queue::assertPushed(UpdateJob::class, 1);
        }

        // Note: We test both current user as well as fresh user object
        //       to make sure cache works as expected
        $this->assertNull($user->getSetting('first_name'));
        $this->assertNull($user->fresh()->getSetting('first_name'));

        // Delete a setting (empty string)
        $this->fakeQueueReset();
        $user->setSetting('first_name', 'Firstname1');
        $user->setSetting('first_name', '');

        if (\config('app.with_ldap')) {
            Queue::assertPushed(UpdateJob::class, 1);
        }

        // Note: We test both current user as well as fresh user object
        //       to make sure cache works as expected
        $this->assertNull($user->getSetting('first_name'));
        $this->assertNull($user->fresh()->getSetting('first_name'));

        // Set multiple settings at once
        $this->fakeQueueReset();
        $user->setSettings([
            'first_name' => 'Firstname2',
            'last_name' => 'Lastname2',
            'country' => null,
        ]);

        // Thanks to job locking it creates a single UserUpdate job
        if (\config('app.with_ldap')) {
            Queue::assertPushed(UpdateJob::class, 1);
        }

        // Note: We test both current user as well as fresh user object
        //       to make sure cache works as expected
        $this->assertSame('Firstname2', $user->getSetting('first_name'));
        $this->assertSame('Firstname2', $user->fresh()->getSetting('first_name'));
        $this->assertSame('Lastname2', $user->getSetting('last_name'));
        $this->assertSame('Lastname2', $user->fresh()->getSetting('last_name'));
        $this->assertNull($user->getSetting('country'));
        $this->assertNull($user->fresh()->getSetting('country'));

        $expected = [
            'currency' => 'CHF',
            'first_name' => 'Firstname2',
            'last_name' => 'Lastname2',
        ];

        $this->assertSame($expected, $user->settings()->orderBy('key')->get()->pluck('value', 'key')->all());

        $expected = [
            'first_name' => 'Firstname2',
            'last_name' => 'Lastname2',
            'unknown' => null,
        ];

        $this->assertSame($expected, $user->getSettings(['first_name', 'last_name', 'unknown']));
    }

    /**
     * Tests for User::users()
     */
    public function testUsers(): void
    {
        $jack = $this->getTestUser('jack@kolab.org');
        $joe = $this->getTestUser('joe@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $wallet = $john->wallets()->first();

        $users = $john->users()->orderBy('email')->get();

        $this->assertCount(4, $users);
        $this->assertSame($jack->id, $users[0]->id);
        $this->assertSame($joe->id, $users[1]->id);
        $this->assertSame($john->id, $users[2]->id);
        $this->assertSame($ned->id, $users[3]->id);

        $users = $jack->users()->orderBy('email')->get();

        $this->assertCount(0, $users);

        $users = $ned->users()->orderBy('email')->get();

        $this->assertCount(4, $users);
    }

    /**
     * Tests for User::walletOwner() (from EntitleableTrait)
     */
    public function testWalletOwner(): void
    {
        $jack = $this->getTestUser('jack@kolab.org');
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        $this->assertSame($john->id, $john->walletOwner()->id);
        $this->assertSame($john->id, $jack->walletOwner()->id);
        $this->assertSame($john->id, $ned->walletOwner()->id);

        // User with no entitlements
        $user = $this->getTestUser('UserAccountA@UserAccount.com');
        $this->assertSame($user->id, $user->walletOwner()->id);
    }

    /**
     * Tests for User::wallet() and User::wallets()
     */
    public function testWallets(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $account_wallet = $john->wallets->first();

        $this->assertSame(1, $john->wallets()->count());
        $this->assertCount(1, $john->wallets);
        $this->assertInstanceOf(Wallet::class, $account_wallet);

        $this->assertSame(1, $ned->wallets()->count());
        $this->assertCount(1, $ned->wallets);
        $this->assertInstanceOf(Wallet::class, $ned->wallets->first());

        $this->assertSame($account_wallet->id, $john->wallet()->id);
        $this->assertSame($account_wallet->id, $ned->wallet()->id);
    }

    /**
     * Test User password validation
     */
    public function testValidatePassword(): void
    {
        Queue::fake();

        $user = $this->getTestUser('user-test@' . \config('app.domain'), ['password' => 'test']);
        $hash = $user->password;
        $ldap = "{SSHA512}7iaw3Ur350mqGo7jwQrpkj9hiYB3Lkc/iBml1JQODbJ6wYX4oOHV+E+IvIh/1nsUNzLDBMxfqa2Ob1f1ACio/w==";
        $attrs = $user->getAttributes();

        // Note: A User has two password properties, on a successful validation missing password should get updated

        // Wrong password
        $user->setRawAttributes(array_merge($attrs, ['password_ldap' => null]));
        $this->assertSame(AuthAttempt::REASON_PASSWORD, $user->validatePassword('wrong'));
        $this->assertTrue($user->password_ldap === null);

        // Valid password (in 'password_ldap' only)
        $user->setRawAttributes(array_merge($attrs, ['password_ldap' => $ldap, 'password' => null]));
        $user->save();
        $user->refresh();
        $this->assertTrue($user->password === null);
        $this->assertTrue($user->validatePassword('test'));
        $this->assertTrue($user->password_ldap == $ldap); // @phpstan-ignore-line
        $this->assertTrue(strlen($user->password) == strlen($hash)); // @phpstan-ignore-line
        $user->refresh();
        $this->assertTrue($user->password_ldap == $ldap); // @phpstan-ignore-line
        $this->assertTrue(strlen($user->password) == strlen($hash)); // @phpstan-ignore-line

        // Valid password (in 'password' only)
        $user->setRawAttributes(array_merge($attrs, ['password_ldap' => null, 'password' => $hash]));
        $user->save();
        $user->refresh();
        $this->assertTrue($user->password_ldap === null);
        $this->assertTrue($user->validatePassword('test'));
        $this->assertTrue(strlen($user->password_ldap) == strlen($ldap)); // @phpstan-ignore-line
        $this->assertTrue(strlen($user->password) == strlen($hash)); // @phpstan-ignore-line
        $user->refresh();
        $this->assertTrue($user->password_ldap == $ldap); // @phpstan-ignore-line
        $this->assertTrue(strlen($user->password) == strlen($hash)); // @phpstan-ignore-line

        // Note: We test other password algorithms in the Password policy tests

        // Expired password
        $user->setSetting('password_expired', Carbon::now()->toDateTimeString());
        $this->assertSame(AuthAttempt::REASON_PASSWORD_EXPIRED, $user->validatePassword('test'));
    }

    /**
     * Tests for User::findAndAuthenticate()
     */
    public function testFindAndAuthenticate(): void
    {
        $user = $this->getTestUser('john@kolab.org');

        // Ensure we validate a token for the user:
        $token = AuthUtils::tokenCreate($user->id);
        $this->assertTrue(isset(User::findAndAuthenticate($user->email, $token)['user']));

        // Ensure we don't validate a token for another user:
        $token = AuthUtils::tokenCreate($this->getTestUser('ned@kolab.org')->id);
        $this->assertFalse(isset(User::findAndAuthenticate($user->email, $token)['user']));
    }

    /**
     * Test User password validation
     */
    public function testBenchmarkFindAndAuthenticatePassword(): void
    {
        Queue::fake();

        $user = $this->getTestUser('user-test@' . \config('app.domain'), ['password' => 'test']);

        // Seed the cache
        User::findAndAuthenticate($user->email, "test");
        $time = Benchmark::measure(static function () use (&$user) {
            User::findAndAuthenticate($user->email, "test");
        }, 10);
        // print("\nTime: $time ms\n");
        // We want this to be faster than the slow default bcrypt algorithm
        $this->assertTrue($time < 10);
    }

    /**
     * Test User token validation
     */
    public function testBenchmarkFindAndAuthenticateToken(): void
    {
        Queue::fake();

        $user = $this->getTestUser('user-test@' . \config('app.domain'), ['password' => 'test']);
        $token = AuthUtils::tokenCreate($user->id);

        // Seed the cache
        User::findAndAuthenticate($user->email, $token);
        $time = Benchmark::measure(static function () use ($user, $token) {
            User::findAndAuthenticate($user->email, $token);
        }, 10);
        // print("\nTime: $time ms\n");
        // We want this to be faster than the slow default bcrypt algorithm
        $this->assertTrue($time < 10);
    }
}
