<?php

namespace Tests\Feature\Controller;

use App\Meet\Room;
use Tests\TestCase;

class MeetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $room = Room::where('name', 'john')->first();
        $room->setSettings(['password' => null, 'locked' => null, 'nomedia' => null]);
    }

    protected function tearDown(): void
    {
        $room = Room::where('name', 'john')->first();
        $room->setSettings(['password' => null, 'locked' => null, 'nomedia' => null]);

        parent::tearDown();
    }

    /**
     * Test joining the room
     *
     * @group meet
     */
    public function testJoinRoom(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $room = Room::where('name', 'john')->first();
        $room->session_id = null;
        $room->save();

        // Unauth access, no session yet
        $response = $this->post("api/v4/meet/rooms/{$room->name}");
        $response->assertStatus(422);

        $json = $response->json();
        $this->assertSame(323, $json['code']);

        // Non-existing room name
        $response = $this->actingAs($john)->post("api/v4/meet/rooms/non-existing");
        $response->assertStatus(404);

        // TODO: Test accessing an existing room of deleted owner

        // Non-owner, no session yet
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}");
        $response->assertStatus(422);

        $json = $response->json();
        $this->assertSame(323, $json['code']);

        // Room owner, no session yet
        $response = $this->actingAs($john)->post("api/v4/meet/rooms/{$room->name}");
        $response->assertStatus(422);

        $json = $response->json();
        $this->assertSame(324, $json['code']);

        $response = $this->actingAs($john)->post("api/v4/meet/rooms/{$room->name}", ['init' => 1]);
        $response->assertStatus(200);

        $json = $response->json();

        $session_id = $room->fresh()->session_id;

        $this->assertSame(Room::ROLE_SUBSCRIBER | Room::ROLE_MODERATOR | Room::ROLE_OWNER, $json['role']);
        $this->assertMatchesRegularExpression('|^wss?://|', $json['token']);
        $this->assertMatchesRegularExpression('|&roomId=' . $session_id . '|', $json['token']);

        $john_token = $json['token'];

        // Non-owner, now the session exists, no 'init' argument
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}");
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame(322, $json['code']);
        $this->assertTrue(empty($json['token']));

        // Non-owner, now the session exists, with 'init', but no 'canPublish' argument
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}", ['init' => 1]);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(Room::ROLE_SUBSCRIBER, $json['role']);
        $this->assertMatchesRegularExpression('|^wss?://|', $json['token']);
        $this->assertMatchesRegularExpression('|&roomId=' . $session_id . '|', $json['token']);
        $this->assertTrue($json['token'] != $john_token);

        // Non-owner, now the session exists, with 'init', and with 'role=PUBLISHER'
        $post = ['canPublish' => true, 'init' => 1];
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(Room::ROLE_PUBLISHER, $json['role']);
        $this->assertMatchesRegularExpression('|^wss?://|', $json['token']);
        $this->assertMatchesRegularExpression('|&roomId=' . $session_id . '|', $json['token']);
        $this->assertTrue($json['token'] != $john_token);
        $this->assertEmpty($json['config']['password']);
        $this->assertEmpty($json['config']['requires_password']);

        // Non-owner, password protected room, password not provided
        $room->setSettings(['password' => 'pass']);
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}");
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame(325, $json['code']);
        $this->assertSame('error', $json['status']);
        $this->assertSame('Failed to join the session. Invalid password.', $json['message']);
        $this->assertEmpty($json['config']['password']);
        $this->assertTrue($json['config']['requires_password']);

        // Non-owner, password protected room, invalid provided
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}", ['password' => 'aa']);
        $response->assertStatus(422);

        $json = $response->json();
        $this->assertSame(325, $json['code']);

        // Non-owner, password protected room, valid password provided
        // TODO: Test without init=1
        $post = ['password' => 'pass', 'init' => 'init'];
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(200);

        // Make sure the room owner can access the password protected room w/o password
        // TODO: Test without init=1
        $post = ['init' => 'init'];
        $response = $this->actingAs($john)->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(200);

        // Test 'nomedia' room option
        $room->setSettings(['nomedia' => 'true', 'password' => null]);

        $post = ['init' => 'init', 'canPublish' => true];
        $response = $this->actingAs($john)->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertSame(Room::ROLE_PUBLISHER & $json['role'], Room::ROLE_PUBLISHER);

        $post = ['init' => 'init', 'canPublish' => true];
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(200);

        $json = $response->json();
        $this->assertSame(Room::ROLE_PUBLISHER & $json['role'], 0);

        // Test opening the session as a sharee of a room
        $room = Room::where('name', 'shared')->first();
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}", ['init' => 1]);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(Room::ROLE_SUBSCRIBER | Room::ROLE_MODERATOR | Room::ROLE_OWNER, $json['role']);
        $this->assertMatchesRegularExpression('|^wss?://|', $json['token']);
    }

    /**
     * Test locked room and join requests
     *
     * @group meet
     */
    public function testJoinRequests(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $room = Room::where('name', 'john')->first();
        $room->session_id = null;
        $room->save();
        $room->setSettings(['password' => null, 'locked' => 'true']);

        // Create the session (also makes sure the owner can access a locked room)
        $response = $this->actingAs($john)->post("api/v4/meet/rooms/{$room->name}", ['init' => 1]);
        $response->assertStatus(200);

        // Non-owner, locked room, invalid/missing input
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}");
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame(326, $json['code']);
        $this->assertSame('error', $json['status']);
        $this->assertSame('Failed to join the session. Room locked.', $json['message']);
        $this->assertTrue($json['config']['locked']);

        // Non-owner, locked room, invalid requestId
        $post = ['nickname' => 'name', 'requestId' => '-----', 'init' => 1];
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(422);

        $json = $response->json();
        $this->assertSame(326, $json['code']);

        // Non-owner, locked room, invalid requestId
        $post = ['nickname' => 'name', 'init' => 1];
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(422);

        $json = $response->json();
        $this->assertSame(326, $json['code']);

        // Non-owner, locked room, valid input
        $reqId = '12345678';
        $post = ['nickname' => 'name', 'requestId' => $reqId, 'picture' => 'data:image/png;base64,01234'];
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertCount(4, $json);
        $this->assertSame(327, $json['code']);
        $this->assertSame('error', $json['status']);
        $this->assertSame('Failed to join the session. Room locked.', $json['message']);
        $this->assertTrue($json['config']['locked']);

        $room->refresh();

        $request = $room->requestGet($reqId);

        $this->assertSame($post['nickname'], $request['nickname']);
        $this->assertSame($post['requestId'], $request['requestId']);

        $room->requestAccept($reqId);

        // Non-owner, locked room, join request accepted
        $post['init'] = 1;
        $post['canPublish'] = true;
        $response = $this->actingAs($jack)->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(200);
        $json = $response->json();

        $this->assertSame(Room::ROLE_PUBLISHER, $json['role']);
        $this->assertMatchesRegularExpression('|^wss?://|', $json['token']);

        // TODO: Test a scenario where both password and lock are enabled
        // TODO: Test accepting/denying as a non-owner moderator
        // TODO: Test somehow websocket communication
        $this->markTestIncomplete();
    }

    /**
     * Test joining the room
     *
     * @group meet
     *
     * @depends testJoinRoom
     */
    public function testJoinRoomGuest(): void
    {
        // There's no easy way to logout the user in the same test after
        // using actingAs(). That's why this is moved to a separate test
        $room = Room::where('name', 'john')->first();

        // Guest, request with screenShare token
        $post = ['canPublish' => true, 'screenShare' => 1, 'init' => 1];
        $response = $this->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(Room::ROLE_PUBLISHER, $json['role']);
        $this->assertMatchesRegularExpression('|^wss?://|', $json['token']);
    }

    /**
     * Test the webhook
     *
     * @group meet
     */
    public function testWebhook(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $room = Room::where('name', 'john')->first();
        $headers = ['X-Auth-Token' => \config('meet.webhook_token')];

        // First, create the session
        $post = ['init' => 1];
        $response = $this->actingAs($john)->post("api/v4/meet/rooms/{$room->name}", $post);
        $response->assertStatus(200);

        $sessionId = $room->fresh()->session_id;

        // Test accepting a join request
        $room->requestSave('1234', ['nickname' => 'test']);

        $post = ['roomId' => $sessionId, 'requestId' => '1234', 'event' => 'joinRequestAccepted'];
        $response = $this->post("api/webhooks/meet", $post);
        $response->assertStatus(403); // 403 because no auth token

        $response = $this->withHeaders($headers)->post("api/webhooks/meet", $post);
        $response->assertStatus(200);

        $request = $room->requestGet('1234');

        $this->assertSame(Room::REQUEST_ACCEPTED, $request['status']);

        // Test denying a join request
        $room->requestSave('1234', ['nickname' => 'test']);

        $post = ['roomId' => $sessionId, 'requestId' => '1234', 'event' => 'joinRequestDenied'];
        $response = $this->withHeaders($headers)->post("api/webhooks/meet", $post);
        $response->assertStatus(200);

        $request = $room->requestGet('1234');

        $this->assertSame(Room::REQUEST_DENIED, $request['status']);

        // Test closing the session
        $post = ['roomId' => $sessionId, 'event' => 'roomClosed'];
        $response = $this->withHeaders($headers)->post("api/webhooks/meet", $post);
        $response->assertStatus(200);

        $this->assertNull($room->fresh()->session_id);
    }
}
