<?php

namespace Tests\Feature\Controller;

use App\Fs\Item;
use App\User;
use App\Utils;
use Illuminate\Support\Facades\Cache;
use Tests\TestCaseFs;

class FsTest extends TestCaseFs
{
    /**
     * Test deleting items (DELETE /api/v4/fs/<item-id>)
     */
    public function testDelete(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $file = $this->getTestFile($john, 'teśt.txt', 'Teśt content', ['mimetype' => 'plain/text']);

        // Unauth access
        $response = $this->delete("api/v4/fs/{$file->id}");
        $response->assertStatus(401);

        // Unauth access
        $response = $this->actingAs($jack)->delete("api/v4/fs/{$file->id}");
        $response->assertStatus(403);

        // Non-existing file
        $response = $this->actingAs($john)->delete("api/v4/fs/123");
        $response->assertStatus(404);

        // File owner access
        $response = $this->actingAs($john)->delete("api/v4/fs/{$file->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("File deleted successfully.", $json['message']);
        $this->assertNull(Item::find($file->id));

        // Note: The file is expected to stay still in the filesystem, we're not testing this here.

        // TODO: Test acting as another user with permissions
    }

    /**
     * Test deleting collections (DELETE /api/v4/fs/<collection-id>)
     */
    public function testDeleteCollection(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $collection = $this->getTestCollection($john, 'Teśt content');

        // File owner access
        $response = $this->actingAs($john)->delete("api/v4/fs/{$collection->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("Collection deleted successfully.", $json['message']);
        $this->assertNull(Item::find($collection->id));
    }

    /**
     * Test file downloads (GET /api/v4/fs/downloads/<id>)
     */
    public function testDownload(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $file = $this->getTestFile($john, 'teśt.txt', 'Teśt content', ['mimetype' => 'plain/text']);

        // Unauth access
        $response = $this->get("api/v4/fs/{$file->id}?downloadUrl=1");
        $response->assertStatus(401);

        $response = $this->actingAs($jack)->get("api/v4/fs/{$file->id}?downloadUrl=1");
        $response->assertStatus(403);

        // Non-existing file
        $response = $this->actingAs($john)->get("api/v4/fs/123456?downloadUrl=1");
        $response->assertStatus(404);

        // Get downloadLink for the file
        $response = $this->actingAs($john)->get("api/v4/fs/{$file->id}?downloadUrl=1");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($file->id, $json['id']);
        $link = $json['downloadUrl'];

        // Fetch the file content
        $response = $this->get(substr($link, strpos($link, '/api/') + 1));
        $response->assertStatus(200)
            ->assertHeader('Content-Disposition', "attachment; filename=test.txt; filename*=utf-8''te%C5%9Bt.txt")
            ->assertHeader('Content-Type', $file->getProperty('mimetype'));

        $this->assertSame('Teśt content', $response->streamedContent());

        // Test acting as another user with read permission
        $permission = $this->getTestFilePermission($file, $jack, 'r');
        $response = $this->actingAs($jack)->get("api/v4/fs/{$permission->key}?downloadUrl=1");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($file->id, $json['id']);
        $link = $json['downloadUrl'];

        // Fetch the file content
        $response = $this->get(substr($link, strpos($link, '/api/') + 1));
        $response->assertStatus(200)
            ->assertHeader('Content-Disposition', "attachment; filename=test.txt; filename*=utf-8''te%C5%9Bt.txt")
            ->assertHeader('Content-Type', $file->getProperty('mimetype'));

        $this->assertSame('Teśt content', $response->streamedContent());

        // Test downloading a multi-chunk file
        $file = $this->getTestFile($john, 'test2.txt', ['T1', 'T2'], ['mimetype' => 'plain/text']);
        $response = $this->actingAs($john)->get("api/v4/fs/{$file->id}?downloadUrl=1");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($file->id, $json['id']);
        $link = $json['downloadUrl'];

        // Fetch the file content
        $response = $this->get(substr($link, strpos($link, '/api/') + 1));
        $response->assertStatus(200)
            ->assertHeader('Content-Disposition', "attachment; filename=test2.txt")
            ->assertHeader('Content-Type', $file->getProperty('mimetype'));

        $this->assertSame('T1T2', $response->streamedContent());
    }

    /**
     * Test fetching/creating/updaing/deleting file permissions (GET|POST|PUT /api/v4/fs/<file-id>/permissions)
     */
    public function testPermissions(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $file = $this->getTestFile($john, 'test1.txt', []);

        // Unauth access not allowed
        $response = $this->get("api/v4/fs/{$file->id}/permissions");
        $response->assertStatus(401);
        $response = $this->post("api/v4/fs/{$file->id}/permissions", []);
        $response->assertStatus(401);

        // Non-existing file
        $response = $this->actingAs($john)->get("api/v4/fs/1234/permissions");
        $response->assertStatus(404);
        $response = $this->actingAs($john)->post("api/v4/fs/1234/permissions", []);
        $response->assertStatus(404);

        // No permissions to the file
        $response = $this->actingAs($jack)->get("api/v4/fs/{$file->id}/permissions");
        $response->assertStatus(403);
        $response = $this->actingAs($jack)->post("api/v4/fs/{$file->id}/permissions", []);
        $response->assertStatus(403);

        // Expect an empty list of permissions
        $response = $this->actingAs($john)->get("api/v4/fs/{$file->id}/permissions");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertSame([], $json['list']);
        $this->assertSame(0, $json['count']);

        // Empty input
        $response = $this->actingAs($john)->post("api/v4/fs/{$file->id}/permissions", []);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertSame(["The user field is required."], $json['errors']['user']);
        $this->assertSame(["The permissions field is required."], $json['errors']['permissions']);

        // Test more input validation
        $post = ['user' => 'user', 'permissions' => 'read'];
        $response = $this->actingAs($john)->post("api/v4/fs/{$file->id}/permissions", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertCount(2, $json['errors']);
        $this->assertSame(["The user must be a valid email address."], $json['errors']['user']);
        $this->assertSame("The file permission is invalid.", $json['errors']['permissions']);

        // Let's add some permission
        $post = ['user' => 'jack@kolab.org', 'permissions' => 'read-only'];
        $response = $this->actingAs($john)->post("api/v4/fs/{$file->id}/permissions", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("File permissions created successfully.", $json['message']);

        $permission = $file->properties()->where('key', 'like', 'share-%')->orderBy('value')->first();

        $this->assertSame("{$jack->email}:r", $permission->value);
        $this->assertSame($permission->key, $json['id']);
        $this->assertSame($jack->email, $json['user']);
        $this->assertSame('read-only', $json['permissions']);
        $this->assertSame(Utils::serviceUrl('file/' . $permission->key), $json['link']);

        // Error handling on use of the same user
        $post = ['user' => 'jack@kolab.org', 'permissions' => 'read-only'];
        $response = $this->actingAs($john)->post("api/v4/fs/{$file->id}/permissions", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("File permission already exists.", $json['errors']['user']);

        // Test update
        $response = $this->actingAs($john)->put("api/v4/fs/{$file->id}/permissions/1234", $post);
        $response->assertStatus(404);

        $post = ['user' => 'jack@kolab.org', 'permissions' => 'read-write'];
        $response = $this->actingAs($john)->put("api/v4/fs/{$file->id}/permissions/{$permission->key}", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("File permissions updated successfully.", $json['message']);

        $permission->refresh();

        $this->assertSame("{$jack->email}:rw", $permission->value);
        $this->assertSame($permission->key, $json['id']);
        $this->assertSame($jack->email, $json['user']);
        $this->assertSame('read-write', $json['permissions']);
        $this->assertSame(Utils::serviceUrl('file/' . $permission->key), $json['link']);

        // Input validation on update
        $post = ['user' => 'jack@kolab.org', 'permissions' => 'read'];
        $response = $this->actingAs($john)->put("api/v4/fs/{$file->id}/permissions/{$permission->key}", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("The file permission is invalid.", $json['errors']['permissions']);

        // Test GET with existing permissions
        $response = $this->actingAs($john)->get("api/v4/fs/{$file->id}/permissions");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(2, $json);
        $this->assertCount(1, $json['list']);
        $this->assertSame(1, $json['count']);

        $this->assertSame($permission->key, $json['list'][0]['id']);
        $this->assertSame($jack->email, $json['list'][0]['user']);
        $this->assertSame('read-write', $json['list'][0]['permissions']);
        $this->assertSame(Utils::serviceUrl('file/' . $permission->key), $json['list'][0]['link']);

        // Delete permission
        $response = $this->actingAs($john)->delete("api/v4/fs/{$file->id}/permissions/1234");
        $response->assertStatus(404);

        $response = $this->actingAs($john)->delete("api/v4/fs/{$file->id}/permissions/{$permission->key}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("File permissions deleted successfully.", $json['message']);

        $this->assertCount(0, $file->properties()->where('key', 'like', 'share-%')->get());
    }

    /**
     * Test fetching file/folders list (GET /api/v4/fs)
     */
    public function testIndex(): void
    {
        // Unauth access not allowed
        $response = $this->get("api/v4/fs");
        $response->assertStatus(401);

        $user = $this->getTestUser('john@kolab.org');

        // Expect an empty list
        $response = $this->actingAs($user)->get("api/v4/fs");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(3, $json);
        $this->assertSame([], $json['list']);
        $this->assertSame(0, $json['count']);
        $this->assertFalse($json['hasMore']);

        // Create some files and test again
        $file1 = $this->getTestFile($user, 'test1.txt', [], ['mimetype' => 'text/plain', 'size' => 12345]);
        $file2 = $this->getTestFile($user, 'test2.gif', [], ['mimetype' => 'image/gif', 'size' => 10000]);
        $folder = $this->getTestCollection($user, 'VVV');

        $response = $this->actingAs($user)->get("api/v4/fs");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(3, $json);
        $this->assertSame(3, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertCount(3, $json['list']);
        $this->assertSame('VVV', $json['list'][0]['name']);
        $this->assertSame($folder->id, $json['list'][0]['id']);
        $this->assertFalse(in_array('size', $json['list'][0]));
        $this->assertFalse(in_array('mimetype', $json['list'][0]));
        $this->assertSame('test1.txt', $json['list'][1]['name']);
        $this->assertSame(12345, $json['list'][1]['size']);
        $this->assertSame($file1->id, $json['list'][1]['id']);
        $this->assertSame('text/plain', $json['list'][1]['mimetype']);
        $this->assertSame('test2.gif', $json['list'][2]['name']);
        $this->assertSame($file2->id, $json['list'][2]['id']);
        $this->assertSame(10000, $json['list'][2]['size']);
        $this->assertSame('image/gif', $json['list'][2]['mimetype']);

        // Searching
        $response = $this->actingAs($user)->get("api/v4/fs?search=t2");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(3, $json);
        $this->assertSame(1, $json['count']);
        $this->assertFalse($json['hasMore']);
        $this->assertCount(1, $json['list']);
        $this->assertSame('test2.gif', $json['list'][0]['name']);
        $this->assertSame($file2->id, $json['list'][0]['id']);

        // TODO: Test paging

        // Make sure incomplete files are skipped
        $file1->type |= Item::TYPE_INCOMPLETE;
        $file1->save();

        $response = $this->actingAs($user)->get("api/v4/fs");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertCount(3, $json);
        $this->assertSame(2, $json['count']);
    }

    /**
     * Test fetching file/folders list (GET /api/v4/fs)
     */
    public function testIndexChildren(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $file1 = $this->getTestFile($john, 'test1.txt', 'Teśt content1', ['mimetype' => 'plain/text']);
        $file2 = $this->getTestFile($john, 'test2.txt', 'Teśt content2', ['mimetype' => 'plain/text']);
        $collection = $this->getTestCollection($john, 'My Test Collection');
        $collection->children()->attach($file1);

        // List files in collection
        $response = $this->actingAs($john)->get("api/v4/fs?parent={$collection->id}");
        $response->assertStatus(200);
        $json = $response->json();

        $list = $json['list'];
        $this->assertSame(1, count($list));
        $this->assertSame($file1->id, $list[0]['id']);

        // List files not in a collection
        $response = $this->actingAs($john)->get("api/v4/fs?type=file");
        $response->assertStatus(200);
        $json = $response->json();

        $list = $json['list'];
        $this->assertSame(1, count($list));
        $this->assertSame($file2->id, $list[0]['id']);

        // Remove from collection
        $collection->children()->detach($file1);

        $response = $this->actingAs($john)->get("api/v4/fs?parent={$collection->id}");
        $response->assertStatus(200);
        $json = $response->json();
        $this->assertSame(0, count($response->json()['list']));
    }

    /**
     * Test fetching file metadata (GET /api/v4/fs/<file-id>)
     */
    public function testShow(): void
    {
        // Unauth access not allowed
        $response = $this->get("api/v4/fs/1234");
        $response->assertStatus(401);

        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $file = $this->getTestFile($john, 'teśt.txt', 'Teśt content', ['mimetype' => 'plain/text']);

        // Non-existing file
        $response = $this->actingAs($jack)->get("api/v4/fs/1234");
        $response->assertStatus(404);

        // Unauthorized access
        $response = $this->actingAs($jack)->get("api/v4/fs/{$file->id}");
        $response->assertStatus(403);

        // Get file metadata
        $response = $this->actingAs($john)->get("api/v4/fs/{$file->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($file->id, $json['id']);
        $this->assertSame($file->updated_at->toDateTimeString(), $json['updated_at']);
        $this->assertSame($file->created_at->toDateTimeString(), $json['created_at']);
        $this->assertSame($file->getProperty('mimetype'), $json['mimetype']);
        $this->assertSame((int) $file->getProperty('size'), $json['size']);
        $this->assertSame($file->getProperty('name'), $json['name']);
        $this->assertSame('file', $json['type']);
        $this->assertTrue($json['isOwner']);
        $this->assertTrue($json['canUpdate']);
        $this->assertTrue($json['canDelete']);
        $this->assertNull($json['parentId']);

        $folder = $this->getTestCollection($john, 'VVV');
        $folder->children()->attach($file);

        // Get file metadata (file in a folder)
        $response = $this->actingAs($john)->get("api/v4/fs/{$file->id}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($folder->id, $json['parentId']);

        // Get file content
        $response = $this->actingAs($john)->get("api/v4/fs/{$file->id}?download=1");
        $response->assertStatus(200)
            ->assertHeader('Content-Disposition', "attachment; filename=test.txt; filename*=utf-8''te%C5%9Bt.txt")
            ->assertHeader('Content-Type', $file->getProperty('mimetype'));

        $this->assertSame('Teśt content', $response->streamedContent());

        // Test acting as a user with file permissions
        $permission = $this->getTestFilePermission($file, $jack, 'r');
        $response = $this->actingAs($jack)->get("api/v4/fs/{$permission->key}");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame($file->id, $json['id']);
        $this->assertFalse($json['isOwner']);
        $this->assertFalse($json['canUpdate']);
        $this->assertFalse($json['canDelete']);
    }

    /**
     * Test creating files (POST /api/v4/fs)
     */
    public function testStore(): void
    {
        // Unauth access not allowed
        $response = $this->post("api/v4/fs");
        $response->assertStatus(401);

        $john = $this->getTestUser('john@kolab.org');

        // Test input validation
        $response = $this->sendRawBody($john, 'POST', "api/v4/fs", [], '');
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame(["The name field is required."], $json['errors']['name']);

        $response = $this->sendRawBody($john, 'POST', "api/v4/fs?name=*.txt", [], '');
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame(["The file name is invalid."], $json['errors']['name']);

        $response = $this->sendRawBody($john, 'POST', "api/v4/fs?name=test.txt&parent=unknown", [], '');
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame(["Specified parent does not exist."], $json['errors']);

        $parent = $this->getTestCollection($john, 'Parent');

        // Create a file - the simple method
        $body = "test content";
        $headers = [];
        $response = $this->sendRawBody($john, 'POST', "api/v4/fs?name=test.txt&parent={$parent->id}", $headers, $body);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("File created successfully.", $json['message']);
        $this->assertMatchesRegularExpression('|^[a-z]+/[a-z-]+$|', $json['mimetype']);
        $this->assertSame(strlen($body), $json['size']);
        $this->assertSame('test.txt', $json['name']);

        $file = Item::find($json['id']);

        $this->assertSame(Item::TYPE_FILE, $file->type);
        $this->assertSame($json['mimetype'], $file->getProperty('mimetype'));
        $this->assertSame($json['size'], (int) $file->getProperty('size'));
        $this->assertSame($json['name'], $file->getProperty('name'));
        $this->assertSame($body, $this->getTestFileContent($file));

        $this->assertSame(1, $file->parents()->count());
        $this->assertSame($parent->id, $file->parents()->first()->id);

        // TODO: Test X-Kolab-Parents
    }

    /**
     * Test creating collections (POST /api/v4/fs?type=collection)
     */
    public function testStoreCollection(): void
    {
        $john = $this->getTestUser('john@kolab.org');

        $params = [
            'name' => "MyTestCollection",
            'deviceId' => "myDeviceId",
        ];

        // Invalid parent
        $response = $this->actingAs($john)->post("api/v4/fs?type=collection", $params + ['parent' => 'unknown']);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame(["Specified parent does not exist."], $json['errors']);

        // Valid input
        $response = $this->actingAs($john)->post("api/v4/fs?type=collection", $params);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);

        $collection = Item::find($json['id']);

        $this->assertSame(Item::TYPE_COLLECTION, $collection->type);
        $this->assertSame($params['name'], $collection->getProperty('name'));
        $this->assertSame($params['deviceId'], $collection->getProperty('deviceId'));
    }

    /**
     * Test creating collections (POST /api/v4/fs?type=collection)
     */
    public function testStoreCollectionMetadata(): void
    {
        $john = $this->getTestUser('john@kolab.org');

        $params = [
            'name' => "MyTestCollection",
            'deviceId' => "myDeviceId",
            'collectionType' => "photoalbum",
            'deduplicate-property' => "localId",
            'deduplicate-value' => "myDeviceId:localId",
            'property-localId' => "myDeviceId:localId",
        ];

        $response = $this->actingAs($john)->post("api/v4/fs?type=collection", $params);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);

        $collection = Item::find($json['id']);

        $this->assertSame(Item::TYPE_COLLECTION, $collection->type);
        $this->assertSame($params['name'], $collection->getProperty('name'));
        $this->assertSame($params['deviceId'], $collection->getProperty('deviceId'));
        $this->assertSame($params['collectionType'], $collection->getProperty('collectionType'));
        $this->assertSame($params['property-localId'], $collection->getProperty('localId'));

        // Deduplicate but update the name and parent
        $parent = $this->getTestCollection($john, 'Parent');
        $params = [
            'name' => "MyTestCollection2",
            'deviceId' => "myDeviceId",
            'parent' => $parent->id,
            'collectionType' => "photoalbum",
            'deduplicate-property' => "localId",
            'deduplicate-value' => "myDeviceId:localId",
        ];

        $response = $this->actingAs($john)->post("api/v4/fs?type=collection", $params);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame($collection->id, $json['id']);
        $this->assertSame($params['name'], $collection->getProperty('name'));

        // Deduplicate again, but without changes
        $parent = $this->getTestCollection($john, 'Parent');
        $response = $this->actingAs($john)->post("api/v4/fs?type=collection", $params);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
    }

    /**
     * Test store item relations (POST /api/v4/fs)
     */
    public function testStoreRelation(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $collection = $this->getTestCollection($john, 'My Test Collection');

        $body = "test content";
        $headers = ["X-Kolab-Parents" => implode(',', [$collection->id])];
        $response = $this->sendRawBody($john, 'POST', "api/v4/fs?name=test.txt", $headers, $body);
        $response->assertStatus(200);

        $json = $response->json();
        $newItem = Item::find($json['id']);
        $this->assertNotNull($newItem);
        $this->assertSame(1, $newItem->parents()->count());
        $this->assertSame($collection->id, $newItem->parents()->first()->id);

        $collection2 = $this->getTestCollection($john, 'My Test Collection2');
        $headers = ["X-Kolab-Parents" => implode(',', [$collection->id, $collection2->id])];
        $response = $this->sendRawBody($john, 'POST', "api/v4/fs?name=test2.txt", $headers, $body);
        $response->assertStatus(200);

        $json = $response->json();
        $newItem = Item::find($json['id']);
        $this->assertNotNull($newItem);
        $this->assertSame(2, $newItem->parents()->count());
    }

    /**
     * Test store item relations (POST /api/v4/fs)
     */
    public function testStoreRelationParameter(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $collection = $this->getTestCollection($john, 'My Test Collection');

        $body = "test content";
        $response = $this->sendRawBody($john, 'POST', "api/v4/fs?name=test.txt&parent={$collection->id}", [], $body);
        $response->assertStatus(200);

        $json = $response->json();
        $newItem = Item::find($json['id']);
        $this->assertNotNull($newItem);
        $this->assertSame(1, $newItem->parents()->count());
        $this->assertSame($collection->id, $newItem->parents()->first()->id);
    }

    /**
     * Test creating files - resumable (POST /api/v4/fs)
     */
    public function testStoreResumable(): void
    {
        $john = $this->getTestUser('john@kolab.org');

        $response = $this->actingAs($john)->post("api/v4/fs?name=test2.txt&media=resumable&size=400");
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame(0, $json['uploaded']);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/',
            $json['uploadId']
        );

        $upload = Cache::get('upload:' . $json['uploadId']);
        $file = Item::find($upload['fileId']);
        $this->assertSame(Item::TYPE_INCOMPLETE | Item::TYPE_FILE, $file->type);

        $uploadId = $json['uploadId'];
        $size = 0;
        $fileContent = '';

        for ($x = 0; $x <= 2; $x++) {
            $body = str_repeat("{$x}", 100);
            $response = $this->sendRawBody(null, 'POST', "api/v4/fs/uploads/{$uploadId}?from={$size}", [], $body);
            $response->assertStatus(200);

            $json = $response->json();
            $size += 100;
            $fileContent .= $body;

            $this->assertSame($size, $json['uploaded']);
            $this->assertSame($uploadId, $json['uploadId']);
        }

        $body = str_repeat("{$x}", 100);
        $response = $this->sendRawBody(null, 'POST', "api/v4/fs/uploads/{$uploadId}?from={$size}", [], $body);
        $response->assertStatus(200);

        $json = $response->json();
        $size += 100;
        $fileContent .= $body;

        $this->assertSame('success', $json['status']);
        // $this->assertSame("", $json['message']);
        $this->assertMatchesRegularExpression('|^[a-z]+/[a-z-]+$|', $json['mimetype']);
        $this->assertSame($size, $json['size']);
        $this->assertSame('test2.txt', $json['name']);

        $file = Item::find($json['id']);

        $this->assertSame(Item::TYPE_FILE, $file->type);
        $this->assertSame($json['mimetype'], $file->getProperty('mimetype'));
        $this->assertSame($json['size'], (int) $file->getProperty('size'));
        $this->assertSame($json['name'], $file->getProperty('name'));
        $this->assertSame($fileContent, $this->getTestFileContent($file));
    }

    /**
     * Test updating files (PUT /api/v4/fs/<file-id>)
     */
    public function testUpdate(): void
    {
        // Unauth access not allowed
        $response = $this->put("api/v4/fs/1234");
        $response->assertStatus(401);

        $john = $this->getTestUser('john@kolab.org');
        $jack = $this->getTestUser('jack@kolab.org');
        $file = $this->getTestFile($john, 'teśt.txt', 'Teśt content', ['mimetype' => 'plain/text']);

        // Non-existing file
        $response = $this->actingAs($john)->put("api/v4/fs/1234", []);
        $response->assertStatus(404);

        // Unauthorized access
        $response = $this->actingAs($jack)->put("api/v4/fs/{$file->id}", []);
        $response->assertStatus(403);

        // Test name validation
        $post = ['name' => 'test/test.txt'];
        $response = $this->actingAs($john)->put("api/v4/fs/{$file->id}", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame(["The file name is invalid."], $json['errors']['name']);

        $post = ['name' => 'new name.txt', 'media' => 'test'];
        $response = $this->actingAs($john)->put("api/v4/fs/{$file->id}", $post);
        $response->assertStatus(422);

        $json = $response->json();

        $this->assertSame('error', $json['status']);
        $this->assertSame("The specified media is invalid.", $json['errors']['media']);

        // Rename a file
        $post = ['name' => 'new namś.txt'];
        $response = $this->actingAs($john)->put("api/v4/fs/{$file->id}", $post);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("File updated successfully.", $json['message']);

        $file->refresh();

        $this->assertSame($post['name'], $file->getProperty('name'));

        // Update file content
        $body = "Test1\nTest2";
        $response = $this->sendRawBody($john, 'PUT', "api/v4/fs/{$file->id}?media=content", [], $body);
        $response->assertStatus(200);

        $json = $response->json();

        $this->assertSame('success', $json['status']);
        $this->assertSame("File updated successfully.", $json['message']);

        $file->refresh();

        $this->assertSame($body, $this->getTestFileContent($file));
        $this->assertMatchesRegularExpression('|^[a-z]+/[a-z-]+$|', $file->getProperty('mimetype'));
        $this->assertSame(strlen($body), (int) $file->getProperty('size'));

        // TODO: Test acting as another user with file permissions
        // TODO: Test media=resumable
    }

    /**
     * Test update item relations (PUT /api/v4/fs/$itemid)
     * Add/Remove/Set
     */
    public function testUpdateRelation(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $file = $this->getTestFile($john, 'test1.txt', 'Teśt content1', ['mimetype' => 'plain/text']);
        $collection1 = $this->getTestCollection($john, 'My Test Collection');
        $collection2 = $this->getTestCollection($john, 'My Test Collection2');

        // Add parents
        $headers = ["X-Kolab-Add-Parents" => implode(',', [$collection1->id])];
        $response = $this->sendRawBody($john, 'PUT', "api/v4/fs/{$file->id}", $headers, '');
        $response->assertStatus(200);
        $this->assertSame('success', $response->json()['status']);

        $parents = $file->parents()->get();
        $this->assertSame(1, count($parents));
        $this->assertSame($collection1->id, $parents->first()->id);

        // Set parents
        $headers = ["X-Kolab-Parents" => implode(',', [$collection1->id, $collection2->id])];
        $response = $this->sendRawBody($john, 'PUT', "api/v4/fs/{$file->id}", $headers, '');
        $response->assertStatus(200);
        $this->assertSame('success', $response->json()['status']);

        $parents = $file->parents()->get();
        $this->assertSame(2, count($parents));

        // Remove parents
        $headers = ["X-Kolab-Remove-Parents" => implode(',', [$collection1->id])];
        $response = $this->sendRawBody($john, 'PUT', "api/v4/fs/{$file->id}", $headers, '');
        $response->assertStatus(200);
        $this->assertSame('success', $response->json()['status']);

        $parents = $file->parents()->get();
        $this->assertSame(1, count($parents));
        $this->assertSame($collection2->id, $parents->first()->id);
    }
}
