<?php

namespace Tests\Feature\Controller\Reseller;

use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SharedFoldersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::useResellerUrl();
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
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');
        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');

        // Non-admin user
        $response = $this->actingAs($user)->get("api/v4/shared-folders");
        $response->assertStatus(403);

        // Admin user
        $response = $this->actingAs($admin)->get("api/v4/shared-folders");
        $response->assertStatus(403);

        // Search with no search criteria
        $response = $this->actingAs($reseller1)->get("api/v4/shared-folders");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        // Search with no matches expected
        $response = $this->actingAs($reseller1)->get("api/v4/shared-folders?search=john@kolab.org");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        $count = in_array('event', config('app.shared_folder_types')) ? 3 : 1;

        // Search by email
        $response = $this->actingAs($reseller1)->get("api/v4/shared-folders?search={$folder->email}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($count, $json['count']);
        $this->assertCount($count, $json['list']);
        $this->assertSame($folder->email, $json['list'][0]['email']);

        // Search by owner
        $response = $this->actingAs($reseller1)->get("api/v4/shared-folders?owner={$user->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($count, $json['count']);
        $this->assertCount($count, $json['list']);
        $this->assertSame($folder->email, $json['list'][0]['email']);
        $this->assertSame($folder->name, $json['list'][0]['name']);

        // Search by owner (Ned is a controller on John's wallets,
        // here we expect only folders assigned to Ned's wallet(s))
        $ned = $this->getTestUser('ned@kolab.org');
        $response = $this->actingAs($reseller1)->get("api/v4/shared-folders?owner={$ned->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertCount(0, $json['list']);

        $response = $this->actingAs($reseller2)->get("api/v4/shared-folders?search={$folder->email}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);

        $response = $this->actingAs($reseller2)->get("api/v4/shared-folders?owner={$user->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['count']);
        $this->assertSame([], $json['list']);
    }

    /**
     * Test fetching shared folder info (GET /api/v4/shared-folders/<folder-id>)
     */
    public function testShow(): void
    {
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $user = $this->getTestUser('john@kolab.org');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $reseller2 = $this->getTestUser('reseller@sample-tenant.dev-local');
        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');

        // Only resellers can access it
        $response = $this->actingAs($user)->get("api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(403);

        $response = $this->actingAs($admin)->get("api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(403);

        $response = $this->actingAs($reseller2)->get("api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(404);

        $response = $this->actingAs($reseller1)->get("api/v4/shared-folders/{$folder->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($folder->id, $json['id']);
        $this->assertSame($folder->email, $json['email']);
        $this->assertSame($folder->name, $json['name']);
    }

    /**
     * Test fetching shared folder status (GET /api/v4/shared-folders/<folder-id>/status)
     */
    public function testStatus(): void
    {
        Queue::fake(); // disable jobs

        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));
        $folder = $this->getTestSharedFolder('folder-mail@kolab.org');

        // This end-point does not exist for folders
        $response = $this->actingAs($reseller1)->get("/api/v4/shared-folders/{$folder->id}/status");
        $response->assertStatus(404);
    }

    /**
     * Test shared folder creating (POST /api/v4/shared-folders)
     */
    public function testStore(): void
    {
        $user = $this->getTestUser('john@kolab.org');
        $admin = $this->getTestUser('jeroen@jeroen.jeroen');
        $reseller1 = $this->getTestUser('reseller@' . \config('app.domain'));

        // Test unauthorized access to reseller API
        $response = $this->actingAs($user)->post("/api/v4/shared-folders", []);
        $response->assertStatus(403);

        // Reseller or admin can't create folders
        $response = $this->actingAs($admin)->post("/api/v4/shared-folders", []);
        $response->assertStatus(403);

        $response = $this->actingAs($reseller1)->post("/api/v4/shared-folders", []);
        $response->assertStatus(404);
    }
}
