<?php

namespace Tests\Feature\Backends;

use App\Backends\LDAP;
use App\Domain;
use App\Entitlement;
use App\Group;
use App\Jobs\Group\UpdateJob;
use App\Package;
use App\Resource;
use App\SharedFolder;
use App\Sku;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LDAPTest extends TestCase
{
    private $ldap_config = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!\config('app.with_ldap')) {
            $this->markTestSkipped();
        }

        $this->ldap_config = [
            'services.ldap.hosts' => \config('services.ldap.hosts'),
        ];

        $this->deleteTestUser('user-ldap-test@' . \config('app.domain'), true);
        $this->deleteTestDomain('testldap.com', true);
        $this->deleteTestGroup('group@kolab.org', true);
        $this->deleteTestResource('test-resource@kolab.org', true);
        $this->deleteTestSharedFolder('test-folder@kolab.org', true);
        // TODO: Remove group members
    }

    protected function tearDown(): void
    {
        \config($this->ldap_config);

        $this->deleteTestUser('user-ldap-test@' . \config('app.domain'), true);
        $this->deleteTestDomain('testldap.com', true);
        $this->deleteTestGroup('group@kolab.org', true);
        $this->deleteTestResource('test-resource@kolab.org', true);
        $this->deleteTestSharedFolder('test-folder@kolab.org', true);
        // TODO: Remove group members

        parent::tearDown();
    }

    /**
     * Test handling connection errors
     *
     * @group ldap
     */
    public function testConnectException(): void
    {
        \config(['services.ldap.hosts' => 'non-existing.host']);

        $this->expectException(\Exception::class);

        LDAP::connect();
    }

    /**
     * Test creating/updating/deleting a domain record
     *
     * @group ldap
     */
    public function testDomain(): void
    {
        Queue::fake();

        $domain = $this->getTestDomain('testldap.com', [
            'type' => Domain::TYPE_EXTERNAL,
            'status' => Domain::STATUS_NEW | Domain::STATUS_ACTIVE,
        ]);

        // Create the domain
        LDAP::createDomain($domain);

        $ldap_domain = LDAP::getDomain($domain->namespace);

        $expected = [
            'associateddomain' => $domain->namespace,
            'inetdomainstatus' => $domain->status,
            'objectclass' => [
                'top',
                'domainrelatedobject',
                'inetdomain',
            ],
        ];

        foreach ($expected as $attr => $value) {
            $this->assertSame($value, $ldap_domain[$attr] ?? null);
        }

        // TODO: Test other attributes, aci, roles/ous

        // Update the domain
        $domain->status |= User::STATUS_LDAP_READY;

        LDAP::updateDomain($domain);

        $expected['inetdomainstatus'] = $domain->status;

        $ldap_domain = LDAP::getDomain($domain->namespace);

        foreach ($expected as $attr => $value) {
            $this->assertSame($value, $ldap_domain[$attr] ?? null);
        }

        // Delete the domain
        LDAP::deleteDomain($domain);

        $this->assertNull(LDAP::getDomain($domain->namespace));
    }

    /**
     * Test creating/updating/deleting a group record
     *
     * @group ldap
     */
    public function testGroup(): void
    {
        Queue::fake();

        $root_dn = \config('services.ldap.hosted.root_dn');
        $group = $this->getTestGroup('group@kolab.org', [
            'members' => ['member1@testldap.com', 'member2@testldap.com'],
        ]);
        $group->setSetting('sender_policy', '["test.com"]');

        // Create the group
        LDAP::createGroup($group);

        $ldap_group = LDAP::getGroup($group->email);

        $expected = [
            'cn' => 'group',
            'dn' => 'cn=group,ou=Groups,ou=kolab.org,' . $root_dn,
            'mail' => $group->email,
            'objectclass' => [
                'top',
                'groupofuniquenames',
                'kolabgroupofuniquenames',
            ],
            'kolaballowsmtpsender' => 'test.com',
            'uniquemember' => [
                'uid=member1@testldap.com,ou=People,ou=kolab.org,' . $root_dn,
                'uid=member2@testldap.com,ou=People,ou=kolab.org,' . $root_dn,
            ],
        ];

        foreach ($expected as $attr => $value) {
            $this->assertSame($value, $ldap_group[$attr] ?? null, "Group {$attr} attribute");
        }

        // Update members
        $group->setAddresses(['member3@testldap.com'], true);
        $group->setSetting('sender_policy', '["test.com","Test.com","-"]');

        LDAP::updateGroup($group);

        // TODO: Should we force this to be always an array?
        $expected['uniquemember'] = 'uid=member3@testldap.com,ou=People,ou=kolab.org,' . $root_dn;
        $expected['kolaballowsmtpsender'] = ['test.com', '-']; // duplicates removed

        $ldap_group = LDAP::getGroup($group->email);

        foreach ($expected as $attr => $value) {
            $this->assertSame($value, $ldap_group[$attr] ?? null, "Group {$attr} attribute");
        }

        $this->assertSame(['member3@testldap.com'], $group->getAddresses());

        // Update members (add non-existing local member, expect it to be aot-removed from the group)
        // Update group name and sender_policy
        $group->setAddresses(['member3@testldap.com', 'member-local@kolab.org'], true);
        $group->name = 'Te(=ść)1';
        $group->save();
        $group->setSetting('sender_policy', null);

        LDAP::updateGroup($group);

        // TODO: Should we force this to be always an array?
        $expected['uniquemember'] = 'uid=member3@testldap.com,ou=People,ou=kolab.org,' . $root_dn;
        $expected['kolaballowsmtpsender'] = null;
        $expected['dn'] = 'cn=Te(\3dść)1,ou=Groups,ou=kolab.org,' . $root_dn;
        $expected['cn'] = 'Te(=ść)1';

        $ldap_group = LDAP::getGroup($group->email);

        foreach ($expected as $attr => $value) {
            $this->assertSame($value, $ldap_group[$attr] ?? null, "Group {$attr} attribute");
        }

        $this->assertSame(['member3@testldap.com'], $group->getAddresses());

        // We called save() twice, and setSettings() three times,
        // this is making sure that there's no job executed by the LDAP backend
        Queue::assertPushed(UpdateJob::class, 5);

        // Delete the group
        LDAP::deleteGroup($group);

        $this->assertNull(LDAP::getGroup($group->email));
    }

    /**
     * Test creating/updating/deleting a resource record
     *
     * @group ldap
     */
    public function testResource(): void
    {
        Queue::fake();

        $root_dn = \config('services.ldap.hosted.root_dn');
        $resource = $this->getTestResource('test-resource@kolab.org', ['name' => 'Test1']);
        $resource->setSetting('invitation_policy', null);

        // Make sure the resource does not exist
        // LDAP::deleteResource($resource);

        // Create the resource
        LDAP::createResource($resource);

        $ldap_resource = LDAP::getResource($resource->email);

        $expected = [
            'cn' => 'Test1',
            'dn' => 'cn=Test1,ou=Resources,ou=kolab.org,' . $root_dn,
            'mail' => $resource->email,
            'objectclass' => [
                'top',
                'kolabresource',
                'kolabsharedfolder',
                'mailrecipient',
            ],
            'kolabfoldertype' => 'event',
            'kolabtargetfolder' => 'shared/Resources/Test1@kolab.org',
            'kolabinvitationpolicy' => null,
            'owner' => null,
            'acl' => 'anyone, p',
        ];

        foreach ($expected as $attr => $value) {
            $ldap_value = $ldap_resource[$attr] ?? null;
            $this->assertSame($value, $ldap_value, "Resource {$attr} attribute");
        }

        // Update resource name and invitation_policy
        $resource->name = 'Te(=ść)1';
        $resource->save();
        $resource->setSetting('invitation_policy', 'manual:john@kolab.org');

        LDAP::updateResource($resource);

        $expected['kolabtargetfolder'] = 'shared/Resources/Te(=ść)1@kolab.org';
        $expected['kolabinvitationpolicy'] = 'ACT_MANUAL';
        $expected['owner'] = 'uid=john@kolab.org,ou=People,ou=kolab.org,' . $root_dn;
        $expected['dn'] = 'cn=Te(\3dść)1,ou=Resources,ou=kolab.org,' . $root_dn;
        $expected['cn'] = 'Te(=ść)1';
        $expected['acl'] = ['john@kolab.org, full', 'anyone, p'];

        $ldap_resource = LDAP::getResource($resource->email);

        foreach ($expected as $attr => $value) {
            $ldap_value = $ldap_resource[$attr] ?? null;
            $this->assertSame($value, $ldap_value, "Resource {$attr} attribute");
        }

        // Remove the invitation policy
        $resource->setSetting('invitation_policy', '[]');

        LDAP::updateResource($resource);

        $expected['acl'] = 'anyone, p';
        $expected['kolabinvitationpolicy'] = null;
        $expected['owner'] = null;

        $ldap_resource = LDAP::getResource($resource->email);

        foreach ($expected as $attr => $value) {
            $ldap_value = $ldap_resource[$attr] ?? null;
            $this->assertSame($value, $ldap_value, "Resource {$attr} attribute");
        }

        // Delete the resource
        LDAP::deleteResource($resource);

        $this->assertNull(LDAP::getResource($resource->email));
    }

    /**
     * Test creating/updating/deleting a shared folder record
     *
     * @group ldap
     */
    public function testSharedFolder(): void
    {
        Queue::fake();

        $root_dn = \config('services.ldap.hosted.root_dn');
        $folder = $this->getTestSharedFolder('test-folder@kolab.org', ['type' => 'event']);
        $folder->setSetting('acl', null);

        // Make sure the shared folder does not exist
        // LDAP::deleteSharedFolder($folder);

        // Create the shared folder
        LDAP::createSharedFolder($folder);

        $ldap_folder = LDAP::getSharedFolder($folder->email);

        $expected = [
            'cn' => 'test-folder',
            'dn' => 'cn=test-folder,ou=Shared Folders,ou=kolab.org,' . $root_dn,
            'mail' => $folder->email,
            'objectclass' => [
                'top',
                'kolabsharedfolder',
                'mailrecipient',
            ],
            'kolabfoldertype' => 'event',
            'kolabtargetfolder' => 'shared/test-folder@kolab.org',
            'acl' => 'anyone, p',
            'alias' => null,
        ];

        foreach ($expected as $attr => $value) {
            $ldap_value = $ldap_folder[$attr] ?? null;
            $this->assertSame($value, $ldap_value, "Shared folder {$attr} attribute");
        }

        // Update folder name and acl
        $folder->name = 'Te(=ść)1';
        $folder->save();
        $folder->setSetting('acl', '["john@kolab.org, read-write","anyone, read-only"]');
        $aliases = ['t1-' . $folder->email, 't2-' . $folder->email];
        $folder->setAliases($aliases);

        LDAP::updateSharedFolder($folder);

        $expected['kolabtargetfolder'] = 'shared/Te(=ść)1@kolab.org';
        $expected['acl'] = ['john@kolab.org, read-write', 'anyone, lrsp'];
        $expected['dn'] = 'cn=Te(\3dść)1,ou=Shared Folders,ou=kolab.org,' . $root_dn;
        $expected['cn'] = 'Te(=ść)1';
        $expected['alias'] = $aliases;

        $ldap_folder = LDAP::getSharedFolder($folder->email);

        foreach ($expected as $attr => $value) {
            $ldap_value = $ldap_folder[$attr] ?? null;
            $this->assertSame($value, $ldap_value, "Shared folder {$attr} attribute");
        }

        // Delete the resource
        LDAP::deleteSharedFolder($folder);

        $this->assertNull(LDAP::getSharedFolder($folder->email));
    }

    /**
     * Test creating/editing/deleting a user record
     *
     * @group ldap
     */
    public function testUser(): void
    {
        Queue::fake();

        $user = $this->getTestUser('user-ldap-test@' . \config('app.domain'));

        LDAP::createUser($user);

        $ldap_user = LDAP::getUser($user->email);

        $expected = [
            'objectclass' => [
                'top',
                'inetorgperson',
                'inetuser',
                'kolabinetorgperson',
                'mailrecipient',
                'person',
                'organizationalPerson',
            ],
            'mail' => $user->email,
            'uid' => $user->email,
            'nsroledn' => [
                'cn=imap-user,' . \config('services.ldap.hosted.root_dn'),
            ],
            'cn' => 'unknown',
            'displayname' => '',
            'givenname' => '',
            'sn' => 'unknown',
            'inetuserstatus' => $user->status,
            'mailquota' => null,
            'o' => '',
            'alias' => null,
        ];

        foreach ($expected as $attr => $value) {
            $this->assertSame($value, $ldap_user[$attr] ?? null);
        }

        // Add aliases, and change some user settings, and entitlements
        $user->setSettings([
            'first_name' => 'Firstname',
            'last_name' => 'Lastname',
            'organization' => 'Org',
            'country' => 'PL',
        ]);
        $user->status |= User::STATUS_IMAP_READY;
        $user->save();
        $aliases = ['t1-' . $user->email, 't2-' . $user->email];
        $user->setAliases($aliases);
        $package_kolab = Package::withEnvTenantContext()->where('title', 'kolab')->first();
        $user->assignPackage($package_kolab);

        LDAP::updateUser($user->fresh());

        $expected['alias'] = $aliases;
        $expected['o'] = 'Org';
        $expected['displayname'] = 'Lastname, Firstname';
        $expected['givenname'] = 'Firstname';
        $expected['cn'] = 'Firstname Lastname';
        $expected['sn'] = 'Lastname';
        $expected['inetuserstatus'] = $user->status;
        $expected['mailquota'] = 5242880;
        $expected['nsroledn'] = null;

        $ldap_user = LDAP::getUser($user->email);

        foreach ($expected as $attr => $value) {
            $this->assertSame($value, $ldap_user[$attr] ?? null);
        }

        // Update entitlements
        $sku_activesync = Sku::withEnvTenantContext()->where('title', 'activesync')->first();
        $sku_groupware = Sku::withEnvTenantContext()->where('title', 'groupware')->first();
        $user->assignSku($sku_activesync, 1);
        Entitlement::where(['sku_id' => $sku_groupware->id, 'entitleable_id' => $user->id])->delete();

        LDAP::updateUser($user->fresh());

        $expected_roles = [
            'activesync-user',
            'imap-user',
        ];

        $ldap_user = LDAP::getUser($user->email);

        $this->assertCount(2, $ldap_user['nsroledn']);

        $ldap_roles = array_map(
            static function ($role) {
                if (preg_match('/^cn=([a-z0-9-]+)/', $role, $m)) {
                    return $m[1];
                }
                return $role;
            },
            $ldap_user['nsroledn']
        );

        $this->assertSame($expected_roles, $ldap_roles);

        // Test degraded user

        $sku_storage = Sku::withEnvTenantContext()->where('title', 'storage')->first();
        $sku_2fa = Sku::withEnvTenantContext()->where('title', '2fa')->first();
        $user->status |= User::STATUS_DEGRADED;
        $user->update(['status' => $user->status]);
        $user->assignSku($sku_storage, 2);
        $user->assignSku($sku_2fa, 1);

        LDAP::updateUser($user->fresh());

        $expected['inetuserstatus'] = $user->status;
        $expected['mailquota'] = \config('app.storage.min_qty') * 1048576;
        $expected['nsroledn'] = [
            'cn=2fa-user,' . \config('services.ldap.hosted.root_dn'),
            'cn=degraded-user,' . \config('services.ldap.hosted.root_dn'),
        ];

        $ldap_user = LDAP::getUser($user->email);

        foreach ($expected as $attr => $value) {
            $this->assertSame($value, $ldap_user[$attr] ?? null);
        }

        // TODO: Test user who's owner is degraded

        // Delete the user
        LDAP::deleteUser($user);

        $this->assertNull(LDAP::getUser($user->email));
    }

    /**
     * Test handling errors on a resource creation
     *
     * @group ldap
     */
    public function testCreateResourceException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to create resource/');

        $resource = new Resource([
            'email' => 'test-non-existing-ldap@non-existing.org',
            'name' => 'Test',
            'status' => Resource::STATUS_ACTIVE,
        ]);

        LDAP::createResource($resource);
    }

    /**
     * Test handling errors on a group creation
     *
     * @group ldap
     */
    public function testCreateGroupException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to create group/');

        $group = new Group([
            'name' => 'test',
            'email' => 'test@testldap.com',
            'status' => Group::STATUS_NEW | Group::STATUS_ACTIVE,
        ]);

        LDAP::createGroup($group);
    }

    /**
     * Test handling errors on a shared folder creation
     *
     * @group ldap
     */
    public function testCreateSharedFolderException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to create shared folder/');

        $folder = new SharedFolder([
            'email' => 'test-non-existing-ldap@non-existing.org',
            'name' => 'Test',
            'status' => SharedFolder::STATUS_ACTIVE,
        ]);

        LDAP::createSharedFolder($folder);
    }

    /**
     * Test handling errors on user creation
     *
     * @group ldap
     */
    public function testCreateUserException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to create user/');

        $user = new User([
            'email' => 'test-non-existing-ldap@non-existing.org',
            'status' => User::STATUS_ACTIVE,
        ]);

        LDAP::createUser($user);
    }

    /**
     * Test handling update of a non-existing domain
     *
     * @group ldap
     */
    public function testUpdateDomainException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/domain not found/');

        $domain = new Domain([
            'namespace' => 'testldap.com',
            'type' => Domain::TYPE_EXTERNAL,
            'status' => Domain::STATUS_NEW | Domain::STATUS_ACTIVE,
        ]);

        LDAP::updateDomain($domain);
    }

    /**
     * Test handling update of a non-existing group
     *
     * @group ldap
     */
    public function testUpdateGroupException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/group not found/');

        $group = new Group([
            'name' => 'test',
            'email' => 'test@testldap.com',
            'status' => Group::STATUS_NEW | Group::STATUS_ACTIVE,
        ]);

        LDAP::updateGroup($group);
    }

    /**
     * Test handling update of a non-existing resource
     *
     * @group ldap
     */
    public function testUpdateResourceException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/resource not found/');

        $resource = new Resource([
            'email' => 'test-resource@kolab.org',
        ]);

        LDAP::updateResource($resource);
    }

    /**
     * Test handling update of a non-existing shared folder
     *
     * @group ldap
     */
    public function testUpdateSharedFolderException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/folder not found/');

        $folder = new SharedFolder([
            'email' => 'test-folder-unknown@kolab.org',
        ]);

        LDAP::updateSharedFolder($folder);
    }

    /**
     * Test handling update of a non-existing user
     *
     * @group ldap
     */
    public function testUpdateUserException(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/user not found/');

        $user = new User([
            'email' => 'test-non-existing-ldap@kolab.org',
            'status' => User::STATUS_ACTIVE,
        ]);

        LDAP::updateUser($user);
    }
}
