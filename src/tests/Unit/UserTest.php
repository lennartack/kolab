<?php

namespace Tests\Unit;

use App\User;
use Tests\TestCase;
use Tests\Utils;

class UserTest extends TestCase
{
    /**
     * Test User::isVirtual()
     */
    public function testIsVirtual(): void
    {
        $user = new User();

        $this->assertFalse($user->isVirtual());

        $user->role = User::ROLE_SERVICE;
        $this->assertTrue($user->isVirtual());

        $user->role = User::ROLE_DEVICE;
        $this->assertTrue($user->isVirtual());

        $user->role = User::ROLE_ADMIN;
        $this->assertFalse($user->isVirtual());

        $user->role = User::ROLE_RESELLER;
        $this->assertFalse($user->isVirtual());

        $user->email = \config('services.imap.admin_login');
        $this->assertTrue($user->isVirtual());
    }

    /**
     * Test User password mutator
     */
    public function testSetPasswordAttribute(): void
    {
        $user = new User(['email' => 'user@email.com']);

        $user->password = 'test';

        $ssh512 = "{SSHA512}7iaw3Ur350mqGo7jwQrpkj9hiYB3Lkc/iBml1JQODbJ"
            . "6wYX4oOHV+E+IvIh/1nsUNzLDBMxfqa2Ob1f1ACio/w==";

        $this->assertMatchesRegularExpression('/^\$2y\$04\$[0-9a-zA-Z\/.]{53}$/', $user->password);
        $this->assertSame($ssh512, $user->password_ldap);
    }

    /**
     * Test User password mutator
     */
    public function testSetPasswordLdapAttribute(): void
    {
        $user = new User(['email' => 'user@email.com']);

        $user->password_ldap = 'test';

        $ssh512 = "{SSHA512}7iaw3Ur350mqGo7jwQrpkj9hiYB3Lkc/iBml1JQODbJ"
            . "6wYX4oOHV+E+IvIh/1nsUNzLDBMxfqa2Ob1f1ACio/w==";

        $this->assertMatchesRegularExpression('/^\$2y\$04\$[0-9a-zA-Z\/.]{53}$/', $user->password);
        $this->assertSame($ssh512, $user->password_ldap);
    }

    /**
     * Test User role mutator
     */
    public function testSetRoleAttribute(): void
    {
        $user = new User(['email' => 'user@email.com']);

        $user->role = User::ROLE_ADMIN;
        $this->assertSame(User::ROLE_ADMIN, $user->role);

        $user->role = User::ROLE_RESELLER;
        $this->assertSame(User::ROLE_RESELLER, $user->role);

        $user->role = null;
        $this->assertNull($user->role);

        $this->expectException(\Exception::class);
        $user->role = 'unknown';
    }

    /**
     * Test basic User funtionality
     */
    public function testStatus(): void
    {
        $statuses = [
            User::STATUS_NEW,
            User::STATUS_ACTIVE,
            User::STATUS_SUSPENDED,
            User::STATUS_DELETED,
            User::STATUS_IMAP_READY,
            User::STATUS_LDAP_READY,
            User::STATUS_DEGRADED,
            User::STATUS_RESTRICTED,
        ];

        $users = Utils::powerSet($statuses);

        foreach ($users as $user_statuses) {
            $user = new User(
                [
                    'email' => 'user@email.com',
                    'status' => \array_sum($user_statuses),
                ]
            );

            $this->assertTrue($user->isNew() === in_array(User::STATUS_NEW, $user_statuses));
            $this->assertTrue($user->isActive() === in_array(User::STATUS_ACTIVE, $user_statuses));
            $this->assertTrue($user->isSuspended() === in_array(User::STATUS_SUSPENDED, $user_statuses));
            $this->assertTrue($user->isDeleted() === in_array(User::STATUS_DELETED, $user_statuses));
            $this->assertTrue($user->isLdapReady() === in_array(User::STATUS_LDAP_READY, $user_statuses));
            $this->assertTrue($user->isImapReady() === in_array(User::STATUS_IMAP_READY, $user_statuses));
            $this->assertTrue($user->isDegraded() === in_array(User::STATUS_DEGRADED, $user_statuses));
            $this->assertTrue($user->isRestricted() === in_array(User::STATUS_RESTRICTED, $user_statuses));
        }
    }

    /**
     * Test setStatusAttribute exception
     */
    public function testStatusInvalid(): void
    {
        $this->expectException(\Exception::class);

        $user = new User(
            [
                'email' => 'user@email.com',
                'status' => 1234567,
            ]
        );
    }

    /**
     * Test basic User funtionality
     */
    public function testStatusText(): void
    {
        $user = new User(['email' => 'user@email.com']);

        $this->assertSame('', $user->statusText());

        $user->status = User::STATUS_NEW
            | User::STATUS_ACTIVE
            | User::STATUS_SUSPENDED
            | User::STATUS_DELETED
            | User::STATUS_IMAP_READY
            | User::STATUS_LDAP_READY
            | User::STATUS_DEGRADED
            | User::STATUS_RESTRICTED;

        $expected = [
            'new (1)',
            'active (2)',
            'suspended (4)',
            'deleted (8)',
            'ldapReady (16)',
            'imapReady (32)',
            'degraded (64)',
            'restricted (128)',
        ];

        $this->assertSame(implode(', ', $expected), $user->statusText());
    }
}
