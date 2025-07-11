<?php

namespace Tests\Feature\Controller\Admin;

use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SharedFoldersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useAdminUrl();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Test shared folders searching (/api/v4/shared-folders)
     */
    public function testIndex(): void
    {
        $user = $this->getTestUser('john@kolab.org');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');

        // Non-admin user
        $response = $this->actingAs($user)->get("api/v4/shared-folders");
        $response->assertStatus(403);

        // Search with no search criteria
        $response = $this->actingAs($admin)->get("api/v4/shared-folders");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);
        $this->assertSame("0 shared folders have been found.", $json['message']);

        // Search with no matches expected
        $response = $this->actingAs($admin)->get("api/v4/shared-folders?search=john@kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        $count = in_array('event', config('app.shared_folder_types')) ? 3 : 1;

        // Search by email
        $response = $this->actingAs($admin)->get("api/v4/shared-folders?search={$folder->email}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($count, $json['count']);
        $this->assertCount($count, $json['list']);
        $this->assertSame($folder->email, $json['list'][0]['email']);

        // Search by owner
        $response = $this->actingAs($admin)->get("api/v4/shared-folders?owner={$user->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($count, $json['count']);
        $this->assertCount($count, $json['list']);
        $this->assertSame("{$count} shared folders have been found.", $json['message']);
        $this->assertSame($folder->email, $json['list'][0]['email']);
        $this->assertSame($folder->name, $json['list'][0]['name']);

        // Search by owner (Ned is a controller on John's wallets,
        // here we expect only folders assigned to Ned's wallet(s))
        $ned = $this->getTestUser('ned@kolab.org');
        $response = $this->actingAs($admin)->get("api/v4/shared-folders?owner={$ned->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertCount(0, $json['list']);
    }

    /**
     * Test fetching shared folder info (GET /api/v4/shared-folders/<folder-id>)
     */
    public function testShow(): void
    {
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $user = $this->getTestUser('john@kolab.org');
        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');

        // Only admins can access it
        $response = $this->actingAs($user)->get("api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->get("api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($folder->id, $json['id']);
        $this->assertSame($folder->email, $json['email']);
        $this->assertSame($folder->name, $json['name']);
        $this->assertSame($folder->type, $json['type']);
    }

    /**
     * Test fetching shared folder status (GET /api/v4/shared-folders/<folder-id>/status)
     */
    public function testStatus(): void
    {
        Queue::fake(); // disable jobs

        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');

        // This end-point does not exist for admins
        $response = $this->actingAs($admin)->get("/api/v4/shared-folders/{$folder->id}/status");
        $response->assertStatus(404);
    }

    /**
     * Test shared folder creating (POST /api/v4/shared-folders)
     */
    public function testStore(): void
    {
        $user = $this->getTestUser('john@kolab.org');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');

        // Test unauthorized access to admin API
        $response = $this->actingAs($user)->post("/api/v4/shared-folders", []);
        $response->assertStatus(403);

        // Admin can't create shared folders
        $response = $this->actingAs($admin)->post("/api/v4/shared-folders", []);
        $response->assertStatus(404);
    }
}
