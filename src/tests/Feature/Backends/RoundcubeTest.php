<?php

namespace Tests\Feature\Backends;

use App\Backends\Roundcube;
use App\Delegation;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RoundcubeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestUser('roundcube@' . \config('app.domain'));
        $this->deleteTestUser('roundcube-delegatee@' . \config('app.domain'));
    }

    protected function tearDown(): void
    {
        $this->deleteTestUser('roundcube@' . \config('app.domain'));
        $this->deleteTestUser('roundcube-delegatee@' . \config('app.domain'));

        parent::tearDown();
    }

    /**
     * Test creating a Roundcube user record (and related data)
     *
     * @group roundcube
     */
    public function testUserCreationAndDeletion(): void
    {
        $user = $this->getTestUser('roundcube@' . \config('app.domain'));
        $user->setSetting('first_name', 'First');
        $user->setSetting('last_name', 'Last');

        $db = Roundcube::dbh();

        // delete the user record if exists
        if ($userid = Roundcube::userId($user->email, false)) {
            $db->table('users')->delete();
        }

        // Create the user
        $userid = Roundcube::userId($user->email);

        $rcuser = $db->table('users')->where('username', $user->email)->first();

        $this->assertTrue(!empty($rcuser));

        $rcidentity = $db->table('identities')->where('user_id', $rcuser->user_id)->first();

        $this->assertSame($user->email, $rcidentity->email);
        $this->assertSame('First Last', $rcidentity->name);
        $this->assertSame(1, $rcidentity->standard);

        // Delete the user
        Roundcube::deleteUser($user->email);

        $this->assertNull($db->table('users')->where('username', $user->email)->first());
        $this->assertNull($db->table('identities')->where('user_id', $rcuser->user_id)->first());
    }

    /**
     * Test creating delegated identities
     *
     * @group roundcube
     */
    public function testCreateDelegatedIdentities(): void
    {
        $delegatee = $this->getTestUser('roundcube-delegatee@' . \config('app.domain'));
        $user = $this->getTestUser('roundcube@' . \config('app.domain'));
        $user->setSetting('first_name', 'First');
        $user->setSetting('last_name', 'Last');

        $db = Roundcube::dbh();
        $db->table('users')->whereIn('username', [$user->email, $delegatee->email])->delete();

        // Test with both user records not existing (yet)
        Roundcube::createDelegatedIdentities($delegatee, $user);

        $this->assertNotNull($delegatee_id = Roundcube::userId($delegatee->email, false));
        $idents = $db->table('identities')->where('user_id', $delegatee_id)
            ->where('email', $user->email)->get();
        $this->assertCount(1, $idents);
        $this->assertSame($user->email, $idents[0]->email);
        $this->assertSame('First Last', $idents[0]->name);
        $this->assertSame(0, $idents[0]->standard);
        $this->assertNull($idents[0]->signature);

        // Test with no delegator user record (yet)
        $db->table('identities')->where('user_id', $delegatee_id)->where('email', $user->email)->delete();
        Roundcube::createDelegatedIdentities($delegatee, $user);

        $idents = $db->table('identities')->where('user_id', $delegatee_id)
            ->where('email', $user->email)->get();
        $this->assertCount(1, $idents);
        $this->assertSame($user->email, $idents[0]->email);
        $this->assertSame('First Last', $idents[0]->name);
        $this->assertSame(0, $idents[0]->standard);
        $this->assertNull($idents[0]->signature);

        // Test with delegator user record existing and his identity too
        $db->table('identities')->where('user_id', $delegatee_id)->where('email', $user->email)->delete();
        $this->assertNotNull($user_id = Roundcube::userId($user->email));
        $db->table('identities')->where('user_id', $user_id)->update(['name' => 'Test']);

        Roundcube::createDelegatedIdentities($delegatee, $user);

        $idents = $db->table('identities')->where('user_id', $delegatee_id)
            ->where('email', $user->email)->get();
        $this->assertCount(1, $idents);
        $this->assertSame($user->email, $idents[0]->email);
        $this->assertSame('Test', $idents[0]->name);
        $this->assertNull($idents[0]->signature);

        // Test that executing again does not create a deplicate identity
        Roundcube::createDelegatedIdentities($delegatee, $user);

        $idents = $db->table('identities')->where('user_id', $delegatee_id)
            ->where('email', $user->email)->get();
        $this->assertCount(1, $idents);

        // TODO: signatures copying?
    }

    /**
     * Test resetting (delegated) identities
     *
     * @group roundcube
     */
    public function testResetIdentities(): void
    {
        Queue::fake();

        // Create two users with aliases and delegation relation
        $delegatee = $this->getTestUser('roundcube-delegatee@' . \config('app.domain'));
        $user = $this->getTestUser('roundcube@' . \config('app.domain'));
        $user->aliases()->create(['alias' => 'alias@' . \config('app.domain')]);
        $delegatee->aliases()->create(['alias' => 'alias-delegatee@' . \config('app.domain')]);

        $delegation = Delegation::create([
            'user_id' => $user->id,
            'delegatee_id' => $delegatee->id,
        ]);

        // Create identities
        $db = Roundcube::dbh();
        $db->table('users')->whereIn('username', [$user->email, $delegatee->email])->delete();
        $id = Roundcube::userId($delegatee->email);

        $emails = [
            $user->email,
            'alias@' . \config('app.domain'),
            $delegatee->email,
            'alias-delegatee@' . \config('app.domain'),
        ];
        sort($emails);

        foreach ($emails as $email) {
            // Note: default identity will be created by userId() above
            if ($email != $delegatee->email) {
                $db->table('identities')->insert([
                    'user_id' => $id,
                    'email' => $email,
                    'name' => 'Test',
                    'changed' => now()->toDateTimeString(),
                ]);
            }
        }

        $idents = $db->table('identities')->where('user_id', $id)->orderBy('email')->pluck('email')->all();
        $this->assertSame($emails, $idents);

        // Test with existing delegation (identities should stay intact)
        Roundcube::resetIdentities($delegatee);

        $idents = $db->table('identities')->where('user_id', $id)->orderBy('email')->pluck('email')->all();
        $this->assertSame($emails, $idents);

        // Test with no delegation
        $delegation->delete();
        Roundcube::resetIdentities($delegatee);

        $idents = $db->table('identities')->where('user_id', $id)->orderBy('email')->pluck('email')->all();
        $this->assertSame(['alias-delegatee@' . \config('app.domain'), $delegatee->email], $idents);
    }
}
