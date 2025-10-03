<?php

namespace Tests\Feature\Controller;

use App\Meet\Room;
use App\Sku;
use Tests\TestCase;

class RoomsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Room::withTrashed()->whereNotIn('name', ['shared', 'john'])->forceDelete();
    }

    protected function tearDown(): void
    {
        Room::withTrashed()->whereNotIn('name', ['shared', 'john'])->forceDelete();

        parent::tearDown();
    }

    /**
     * Test deleting a room (DELETE /api/v4/rooms/<room-id>)
     */
    public function testDestroy(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $room = $this->getTestRoom('test', $john->wallets()->first(), [], [], 'group-room');
        $room->setConfig(['acl' => 'jack@kolab.org, full']);

        // Unauth access not allowed
        $response = $this->delete("api/v4/rooms/{$room->id}");
        $response->assertStatus(401);

        // Non-existing room name
        $response = $this->actingAs($john)->delete("api/v4/rooms/non-existing");
        $response->assertStatus(404);

        // Non-owner (sharee also can't delete the room)
        $response = $this->actingAs($jack)->delete("api/v4/rooms/{$room->id}");
        $response->assertStatus(403);

        // Room owner
        $response = $this->actingAs($john)->delete("api/v4/rooms/{$room->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Room deleted successfully.", $json['message']);
    }

    /**
     * Test listing user rooms (GET /api/v4/rooms)
     */
    public function testIndex(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        // Unauth access not allowed
        $response = $this->get("api/v4/rooms");
        $response->assertStatus(401);

        // John has two rooms
        $response = $this->actingAs($john)->get("api/v4/rooms");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(2, $json['count']);
        $this->assertCount(2, $json['list']);
        $this->assertSame('john', $json['list'][0]['name']);
        $this->assertSame("Standard room", $json['list'][0]['description']);
        $this->assertSame('shared', $json['list'][1]['name']);
        $this->assertSame("Shared room", $json['list'][1]['description']);

        // Ned has no rooms, but is the John's wallet controller
        $response = $this->actingAs($ned)->get("api/v4/rooms");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(2, $json['count']);
        $this->assertCount(2, $json['list']);
        $this->assertSame('john', $json['list'][0]['name']);
        $this->assertSame('shared', $json['list'][1]['name']);

        // Jack has no rooms (one will be aot-created), but John shares one with him
        $response = $this->actingAs($jack)->get("api/v4/rooms");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(2, $json['count']);
        $this->assertCount(2, $json['list']);
        $jack_room = $jack->rooms()->first();
        $this->assertTrue(in_array($jack_room->name, [$json['list'][0]['name'], $json['list'][1]['name']]));
        $this->assertTrue(in_array('shared', [$json['list'][0]['name'], $json['list'][1]['name']]));
    }

    /**
     * Test configuring the room (POST api/v4/rooms/<room-id>/config)
     */
    public function testSetConfig(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $room = $this->getTestRoom('test', $john->wallets()->first(), [], [], 'group-room');

        // Unauth access not allowed
        $response = $this->post("api/v4/rooms/{$room->name}/config", []);
        $response->assertStatus(401);

        // Non-existing room name
        $response = $this->actingAs($john)->post("api/v4/rooms/non-existing/config", []);
        $response->assertStatus(404);

        // Non-owner
        $response = $this->actingAs($jack)->post("api/v4/rooms/{$room->name}/config", []);
        $response->assertStatus(403);

        // Room owner
        $response = $this->actingAs($john)->post("api/v4/rooms/{$room->name}/config", []);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Room configuration updated successfully.", $json['message']);

        // Set password and room lock
        $post = ['password' => 'aaa', 'locked' => 1];
        $response = $this->actingAs($john)->post("api/v4/rooms/{$room->name}/config", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Room configuration updated successfully.", $json['message']);
        $room->refresh();
        $this->assertSame('aaa', $room->getSetting('password'));
        $this->assertSame('true', $room->getSetting('locked'));

        // Unset password and room lock
        $post = ['password' => '', 'locked' => 0];
        $response = $this->actingAs($john)->post("api/v4/rooms/{$room->name}/config", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Room configuration updated successfully.", $json['message']);
        $this->assertNull($room->getSetting('password'));
        $this->assertNull($room->getSetting('locked'));

        // Test invalid option error
        $post = ['password' => 'eee', 'unknown' => 0];
        $response = $this->actingAs($john)->post("api/v4/rooms/{$room->name}/config", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('error', $json['status']);
        $this->assertSame("The requested configuration parameter is not supported.", $json['errors']['unknown']);

        $room->refresh();
        $this->assertSame('eee', $room->getSetting('password'));

        // Test ACL
        $post = ['acl' => ['jack@kolab.org, full', 'test, full', 'ned@kolab.org, read-only']];
        $response = $this->actingAs($john)->post("api/v4/rooms/{$room->id}/config", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']['acl']);
        $this->assertSame("The specified email address is invalid.", $json['errors']['acl'][1]);
        $this->assertSame("The specified permission is invalid.", $json['errors']['acl'][2]);
        $this->assertSame([], $room->getConfig()['acl']);

        $post = ['acl' => ['jack@kolab.org, full']];
        $response = $this->actingAs($john)->post("api/v4/rooms/{$room->id}/config", $post);
        $response->assertStatus(200);

        $this->assertSame(['jack@kolab.org, full'], $room->getConfig()['acl']);

        // Acting as Jack
        $post = ['password' => '123', 'acl' => ['joe@kolab.org, full']];
        $response = $this->actingAs($jack)->post("api/v4/rooms/{$room->id}/config", $post);
        $response->assertStatus(200);

        $this->assertSame('123', $room->getConfig()['password']);
        $this->assertSame(['jack@kolab.org, full'], $room->getConfig()['acl']);

        // Acting as Ned
        $post = ['password' => '123456'];
        $response = $this->actingAs($ned)->post("api/v4/rooms/{$room->id}/config", $post);
        $response->assertStatus(200);

        $this->assertSame('123456', $room->getConfig()['password']);
    }

    /**
     * Test getting a room info (GET /api/v4/rooms/<room-id>)
     */
    public function testShow(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $wallet = $john->wallets()->first();
        $room = $this->getTestRoom(
            'test',
            $wallet,
            ['description' => 'desc'],
            ['password' => 'pass', 'locked' => true, 'acl' => []],
            'group-room'
        );

        // Unauth access not allowed
        $response = $this->get("api/v4/rooms/{$room->id}");
        $response->assertStatus(401);

        // Non-existing room name
        $response = $this->actingAs($john)->get("api/v4/rooms/non-existing");
        $response->assertStatus(404);

        // Non-owner
        $response = $this->actingAs($jack)->get("api/v4/rooms/{$room->id}");
        $response->assertStatus(403);

        // Room owner
        $response = $this->actingAs($john)->get("api/v4/rooms/{$room->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($room->id, $json['id']);
        $this->assertSame('test', $json['name']);
        $this->assertSame('desc', $json['description']);
        $this->assertFalse($json['isDeleted']);
        $this->assertTrue($json['isOwner']);
        $this->assertTrue($json['canUpdate']);
        $this->assertTrue($json['canDelete']);
        $this->assertTrue($json['canShare']);
        $this->assertCount(1, $json['skus']);
        $this->assertSame([], $json['config']['acl']);
        $this->assertSame('pass', $json['config']['password']);
        $this->assertTrue($json['config']['locked']);
        $this->assertSame($wallet->id, $json['wallet']['id']);
        $this->assertSame($wallet->currency, $json['wallet']['currency']);
        $this->assertSame($wallet->balance, $json['wallet']['balance']);

        // Another wallet controller
        $response = $this->actingAs($ned)->get("api/v4/rooms/{$room->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($room->id, $json['id']);
        $this->assertTrue($json['isOwner']);
        $this->assertTrue($json['canUpdate']);
        $this->assertTrue($json['canDelete']);
        $this->assertTrue($json['canShare']);

        // Privileged user
        $room->setConfig(['acl' => ['jack@kolab.org, full']]);
        $response = $this->actingAs($jack)->get("api/v4/rooms/{$room->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($room->id, $json['id']);
        $this->assertFalse($json['isOwner']);
        $this->assertTrue($json['canUpdate']);
        $this->assertFalse($json['canDelete']);
        $this->assertFalse($json['canShare']);
        $this->assertSame('pass', $json['config']['password']);
        $this->assertTrue(empty($json['config']['acl']));
    }

    /**
     * Test getting a room entitlements (GET /api/v4/rooms/<room-id>/skus)
     */
    public function testSkus(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $room = Room::where('name', 'john')->first();

        // Unauth access not allowed
        $response = $this->get("api/v4/rooms/{$room->id}/skus");
        $response->assertStatus(401);

        // Non-existing room name
        $response = $this->actingAs($john)->get("api/v4/rooms/non-existing/skus");
        $response->assertStatus(404);

        // Non-owner (the room is shared with Jack, but he should not see entitlements)
        $response = $this->actingAs($jack)->get("api/v4/rooms/{$room->id}/skus");
        $response->assertStatus(403);

        // Room owner
        $response = $this->actingAs($john)->get("api/v4/rooms/{$room->id}/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json['list']);
        $this->assertSame('room', $json['list'][0]['title']);
        $this->assertTrue($json['list'][0]['enabled']);
        $this->assertSame('group-room', $json['list'][1]['title']);
        $this->assertFalse($json['list'][1]['enabled']);

        // Room's wallet controller, not owner
        $response = $this->actingAs($ned)->get("api/v4/rooms/{$room->id}/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json['list']);
        $this->assertSame('room', $json['list'][0]['title']);
        $this->assertTrue($json['list'][0]['enabled']);
        $this->assertSame('group-room', $json['list'][1]['title']);
        $this->assertFalse($json['list'][1]['enabled']);

        // Test non-controller user, expect no group-room SKU on the list
        $room = $this->getTestRoom('test', $jack->wallets()->first());

        $response = $this->actingAs($jack)->get("api/v4/rooms/{$room->id}/skus");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(1, $json['list']);
        $this->assertSame('room', $json['list'][0]['title']);
        $this->assertTrue($json['list'][0]['enabled']);
    }

    /**
     * Test creating a room (POST /api/v4/rooms)
     */
    public function testStore(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');

        // Unauth access not allowed
        $response = $this->post("api/v4/rooms", []);
        $response->assertStatus(401);

        // Only wallet controllers can create rooms (for now)
        $response = $this->actingAs($jack)->post("api/v4/rooms", []);
        $response->assertStatus(403);

        // Description too long
        $post = ['description' => str_repeat('.', 192)];
        $response = $this->actingAs($john)->post("api/v4/rooms", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('error', $json['status']);
        $this->assertSame("The description may not be greater than 191 characters.", $json['errors']['description'][0]);

        // Successful room creation
        $post = ['description' => 'test123'];
        $response = $this->actingAs($john)->post("api/v4/rooms", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Room created successfully.", $json['message']);

        $room = Room::where('description', $post['description'])->first();

        $this->assertSame($room->wallet()->id, $john->wallet()->id);
        $this->assertSame('room', $room->entitlements()->first()->sku->title);

        // Successful room creation (acting as a room controller), non-default SKU
        $sku = Sku::withObjectTenantContext($ned)->where('title', 'group-room')->first();
        $post = ['description' => 'test456', 'skus' => [$sku->id => 1]];
        $response = $this->actingAs($ned)->post("api/v4/rooms", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Room created successfully.", $json['message']);

        $room = Room::where('description', $post['description'])->first();

        $this->assertSame($room->wallet()->id, $john->wallet()->id);
        $this->assertSame($sku->id, $room->entitlements()->first()->sku_id);
    }

    /**
     * Test updating a room (PUT /api/v4/rooms</room-id>)
     */
    public function testUpdate(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $ned = $this->getTestUser('ned@kolab.org');
        $wallet = $john->wallets()->first();
        $room = $this->getTestRoom('test', $wallet, [], [], 'group-room');

        // Unauth access not allowed
        $response = $this->put("api/v4/rooms/{$room->id}", []);
        $response->assertStatus(401);

        // Only wallet controllers can create rooms (for now)
        $response = $this->actingAs($jack)->put("api/v4/rooms/{$room->id}", []);
        $response->assertStatus(403);

        // Description too long
        $post = ['description' => str_repeat('.', 192)];
        $response = $this->actingAs($john)->put("api/v4/rooms/{$room->id}", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('error', $json['status']);
        $this->assertSame("The description may not be greater than 191 characters.", $json['errors']['description'][0]);

        // Successful room update (room owner)
        $post = ['description' => '123'];
        $response = $this->actingAs($john)->put("api/v4/rooms/{$room->id}", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Room updated successfully.", $json['message']);

        $room->refresh();
        $this->assertSame($post['description'], $room->description);

        // Successful room update (acting as a room controller)
        $post = ['description' => '456'];
        $response = $this->actingAs($ned)->put("api/v4/rooms/{$room->id}", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Room updated successfully.", $json['message']);

        $room->refresh();
        $this->assertSame($post['description'], $room->description);

        // Successful room update (acting as a sharee)
        $room->setConfig(['acl' => 'jack@kolab.org, full']);
        $post = ['description' => '789'];
        $response = $this->actingAs($jack)->put("api/v4/rooms/{$room->id}", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame('success', $json['status']);
        $this->assertSame("Room updated successfully.", $json['message']);

        $room->refresh();
        $this->assertSame($post['description'], $room->description);

        // Test changing the room SKU (from 'group-room' to 'room')
        $sku = Sku::withObjectTenantContext($ned)->where('title', 'room')->first();
        $post = ['skus' => [$sku->id => 1]];
        $response = $this->actingAs($ned)->put("api/v4/rooms/{$room->id}", $post);
        $response->assertStatus(200);

        $entitlements = $room->entitlements()->get();
        $this->assertCount(1, $entitlements);
        $this->assertSame($sku->id, $entitlements[0]->sku_id);
        $this->assertNull($room->getSetting('acl'));
    }
}
