<?php

namespace Tests\Feature\Controller;

use App\Fs\Item;
use App\Fs\Lock;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCaseFs;

class DAVTest extends TestCaseFs
{
    /**
     * Test basic COPY requests
     */
    public function testCopy(): void
    {
        $host = trim(\config('app.url'), '/');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';
        $john = $this->getTestUser('john@kolab.org');

        [$folders, $files] = $this->initTestStorage($john);

        // Test with no Authorization header
        $response = $this->davRequest('COPY', "{$root}/test1.txt", '', null, ['Destination' => "{$host}/{$root}/copied1.txt"]);
        $response->assertStatus(401);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Test copying a non-existing file
        $response = $this->davRequest('COPY', "{$root}/unknown", '', $john, ['Destination' => "{$host}/{$root}/copied.txt"]);
        $response->assertNoContent(404);

        // Test a file copy into non-existing location
        $response = $this->davRequest('COPY', "{$root}/test1.txt", '', $john, ['Destination' => "{$host}/{$root}/unknown/test1.txt"]);
        $response->assertNoContent(409);

        // Test a file copy "in place" with rename
        $response = $this->davRequest('COPY', "{$root}/test1.txt", '', $john, ['Destination' => "{$host}/{$root}/copied1.txt"]);
        $response->assertNoContent(201);

        $all_items = array_merge(array_column($files, 'id'), array_column($folders, 'id'));
        $file = $john->fsItems()->whereNotIn('id', $all_items)->first();
        $this->assertSame($files[0]->type, $file->type);
        $this->assertSame($files[0]->updated_at->getTimestamp(), $file->updated_at->getTimestamp());
        $this->assertCount(0, $file->parents()->get());
        $this->assertSame('copied1.txt', $file->getProperty('name'));
        $this->assertSame('text/plain', $file->getProperty('mimetype'));
        $this->assertSame('13', $file->getProperty('size'));
        $all_items[] = $file->id;
        $copied = $file;

        // Test an empty folder copy "in place" with rename
        $empty_folder = $this->getTestCollection($john, 'folder-empty');
        $all_items[] = $empty_folder->id;
        $response = $this->davRequest('COPY', "{$root}/folder-empty", '', $john, ['Destination' => "{$host}/{$root}/folder-copy", 'Depth' => 'infinity']);
        $response->assertNoContent(201);

        $folder = $john->fsItems()->whereNotIn('id', $all_items)->first();
        $this->assertSame($empty_folder->type, $folder->type);
        $this->assertSame($empty_folder->updated_at->getTimestamp(), $folder->updated_at->getTimestamp());
        $this->assertSame('folder-copy', $folder->getProperty('name'));
        $this->assertCount(0, $folder->parents()->get());

        // Copying non-empty folders
        // Add an extra file into /folder1/folder2 folder
        $file = $this->getTestFile($john, 'test5.txt', 'Test con5', ['mimetype' => 'text/plain']);
        $folders[1]->children()->attach($file);

        $response = $this->davRequest('COPY', "{$root}/folder1", '', $john, ['Destination' => "{$host}/{$root}/folder-copy/folder1", 'Depth' => 'infinity']);
        $response->assertNoContent(201);

        $this->assertCount(1, $children = $folder->children()->get());
        $copy = $children[0];
        $this->assertSame(Item::TYPE_COLLECTION, $copy->type);
        $this->assertSame('folder1', $copy->getProperty('name'));
        $this->assertCount(1, $copy->parents()->get());
        $children = $copy->children()->select('fs_items.*')
            ->selectRaw('(select value from fs_properties where fs_items.id = fs_properties.item_id'
                . ' and fs_properties.key = \'name\') as name')
            ->orderBy('name')
            ->get()
            ->keyBy('name');
        $this->assertSame(['folder2', 'test3.txt', 'test4.txt'], $children->pluck('name')->all());
        $children = $children['folder2']->children()->select('fs_items.*')
            ->selectRaw('(select value from fs_properties where fs_items.id = fs_properties.item_id'
                . ' and fs_properties.key = \'name\') as name')
            ->orderBy('name')
            ->get()
            ->keyBy('name');
        $this->assertSame(['test5.txt'], $children->pluck('name')->all());
        $this->assertSame('Test con5', $this->getTestFileContent($children['test5.txt']));

        // Copy a file into an existing location (with "Overwrite:F" header)
        $response = $this->davRequest('COPY', "{$root}/test2.txt", '', $john, ['Destination' => "{$host}/{$root}/copied1.txt", 'Overwrite' => 'F']);
        $response->assertStatus(412);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Copy a file into an existing location (without "Overwrite:F" header)
        $response = $this->davRequest('COPY', "{$root}/folder1/folder2/test5.txt", '', $john, ['Destination' => "{$host}/{$root}/copied1.txt"]);
        $response->assertNoContent(204);

        $this->assertTrue($copied->fresh()->trashed());
        $copied = $john->fsItems()
            ->whereRaw('id in (select item_id from fs_properties where `key` = \'name\' and `value` = \'copied1.txt\')')
            ->get();
        $this->assertCount(1, $copied);
        $this->assertTrue($children['test5.txt']->id != $copied[0]->id);
        $this->assertSame('copied1.txt', $copied[0]->getProperty('name'));
        $this->assertSame('Test con5', $this->getTestFileContent($copied[0]));

        // TODO: Test copying a collection with Depth:0 (and Depth:1) header
        // TODO: Make sure a copy /A/ into /A/B/ does not lead to an infinite recursion
        $this->markTestIncomplete();
    }

    /**
     * Test basic DELETE requests
     */
    public function testDelete(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';

        [$folders, $files] = $this->initTestStorage($john);
        $folders[] = $this->getTestCollection($john, 'folder3');
        $files[] = $this->getTestFile($john, 'test5.txt', 'Test');
        $folders[0]->children()->attach($folders[2]);
        $folders[2]->children()->attach($files[4]);

        // Test with no Authorization header
        $response = $this->davRequest('DELETE', "{$root}/test1.txt");
        $response->assertStatus(401);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Test non-existing location
        $response = $this->davRequest('DELETE', "{$root}/unknown", '', $john);
        $response->assertNoContent(404);

        // Test deleting a file in the root
        $response = $this->davRequest('DELETE', "{$root}/test1.txt", '', $john);
        $response->assertNoContent(204);

        $this->assertTrue($files[0]->fresh()->trashed());

        // Test deleting a file in a folder
        $response = $this->davRequest('DELETE', "{$root}/folder1/test3.txt", '', $john);
        $response->assertNoContent(204);

        $this->assertTrue($files[2]->fresh()->trashed());

        // Test deleting a folder
        $response = $this->davRequest('DELETE', "{$root}/folder1", '', $john);
        $response->assertNoContent(204);

        $this->assertTrue($folders[0]->fresh()->trashed());
        $this->assertTrue($folders[1]->fresh()->trashed());
        $this->assertTrue($folders[2]->fresh()->trashed());
        $this->assertTrue($files[3]->fresh()->trashed());
        $this->assertTrue($files[4]->fresh()->trashed());
        $this->assertFalse($files[1]->fresh()->trashed());

        // Test deleting the root
        $response = $this->davRequest('DELETE', $root, '', $john);
        $response->assertNoContent(403);

        $this->assertFalse($files[1]->fresh()->trashed());
    }

    /**
     * Test basic DELETE requests to locked nodes
     */
    public function testDeleteLocked(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';

        // Create the following structure
        // - folder1/
        //     - folder2/
        //          - test1.txt
        //          - test2.txt

        $folder1 = $this->getTestCollection($john, 'folder1');
        $folder2 = $this->getTestCollection($john, 'folder2');
        $file1 = $this->getTestFile($john, 'test1.txt', 'Test');
        $file2 = $this->getTestFile($john, 'test2.txt', 'Test');

        $folder1->children()->attach($folder2);
        $folder2->children()->attach([$file1, $file2]);

        $lock1 = $folder1->locks()->create([
            'token' => 'testtoken',
            'depth' => 0,
            'scope' => Lock::SCOPE_SHARED,
            'timeout' => 1800,
            'owner' => 'test',
        ]);

        // Test deleting a locked folder
        $response = $this->davRequest('DELETE', "{$root}/folder1", '', $john);
        $response->assertStatus(423);

        // TODO: Assert XML response
        $this->assertFalse($folder1->fresh()->trashed());

        // Test deleting a file in a locked folder
        $response = $this->davRequest('DELETE', "{$root}/folder1/folder2/test1.txt", '', $john);
        $response->assertStatus(423);

        // TODO: Assert XML response
        $this->assertFalse($file1->fresh()->trashed());

        // Test deleting a folder that has a locked child
        $lock1->delete();
        $lock2 = $file1->locks()->create([
            'token' => 'testtoken',
            'depth' => 0,
            'scope' => Lock::SCOPE_SHARED,
            'timeout' => 1800,
            'owner' => 'test',
        ]);
        $response = $this->davRequest('DELETE', "{$root}/folder1", '', $john);
        $response->assertStatus(423);

        // TODO: Assert XML response
        $this->assertFalse($folder1->fresh()->trashed());

        // TODO: Test successful deletion with 'If' header
    }

    /**
     * Test basic GET requests
     */
    public function testGet(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';
        $content = [
            'Test content1',
            'Test content2',
            'Test content3',
        ];
        $content_length = strlen(implode('', $content));
        $file = $this->getTestFile($john, 'test1.txt', $content, ['mimetype' => 'text/plain']);

        // Test with no Authorization header
        $response = $this->davRequest('GET', "{$root}/test1.txt");
        $response->assertStatus(401);
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeader('WWW-Authenticate', 'Basic realm="Kolab/DAV", charset="UTF-8"');

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Test non-existing location
        $response = $this->davRequest('GET', "{$root}/unknown", '', $john);
        $response->assertNoContent(404);

        // Test with a valid Authorization header
        $response = $this->davRequest('GET', "{$root}/test1.txt", '', $john);
        $response->assertStatus(200);
        $response->assertStreamedContent(implode('', $content));

        // Test Range header
        $range = 'bytes=0-' . $content_length - 1;
        $response = $this->davRequest('GET', "{$root}/test1.txt", '', $john, ['Range' => $range]);
        $response->assertStatus(206);
        $response->assertStreamedContent(implode('', $content));
        $response->assertHeader('Content-Length', $content_length);
        $response->assertHeader('Content-Range', str_replace('=', ' ', $range) . '/' . $content_length);

        $range = 'bytes=5-' . strlen($content[0]) - 1;
        $response = $this->davRequest('GET', "{$root}/test1.txt", '', $john, ['Range' => $range]);
        $response->assertStatus(206);
        $response->assertStreamedContent('content1');
        $response->assertHeader('Content-Length', strlen('content1'));
        $response->assertHeader('Content-Range', str_replace('=', ' ', $range) . '/' . $content_length);

        $range = sprintf('bytes=%d-%d', strlen($content[0]), strlen($content[0]) + 3);
        $response = $this->davRequest('GET', "{$root}/test1.txt", '', $john, ['Range' => $range]);
        $response->assertStatus(206);
        $response->assertStreamedContent('Test');
        $response->assertHeader('Content-Length', strlen('Test'));
        $response->assertHeader('Content-Range', str_replace('=', ' ', $range) . '/' . $content_length);

        $range = 'bytes=12-26';
        $response = $this->davRequest('GET', "{$root}/test1.txt", '', $john, ['Range' => $range]);
        $response->assertStatus(206);
        $response->assertStreamedContent('1Test content2T');
        $response->assertHeader('Content-Length', strlen('1Test content2T'));
        $response->assertHeader('Content-Range', str_replace('=', ' ', $range) . '/' . $content_length);

        // Test GET on a collection
        $folder = $this->getTestCollection($john, 'folder1');
        $response = $this->davRequest('GET', "{$root}/folder1", '', $john);
        $response->assertStatus(501);

        // TODO: Test big files >10MB
    }

    /**
     * Test basic HEAD requests
     */
    public function testHead(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';
        $file = $this->getTestFile($john, 'test1.txt', 'Test content1', ['mimetype' => 'text/plain']);

        // Test with no Authorization header
        $response = $this->davRequest('HEAD', "{$root}/test1.txt");
        $response->assertNoContent(401);

        // Test non-existing location
        $response = $this->davRequest('HEAD', "{$root}/unknown", '', $john);
        $response->assertNoContent(404);

        // Test with a valid Authorization header
        $response = $this->davRequest('HEAD', "{$root}/test1.txt", '', $john);
        $response->assertNoContent(200);

        // TODO: Test HEAD on a collection
    }

    /**
     * Test basic LOCK requests (RFC 4918)
     */
    public function testLock(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';
        $file = $this->getTestFile($john, 'test1.txt', 'Test content1', ['mimetype' => 'text/plain']);

        $xml = <<<EOF
            <d:lockinfo xmlns:d='DAV:'>
                <d:lockscope><d:exclusive/></d:lockscope>
                <d:locktype><d:write/></d:locktype>
                <d:owner>
                    <d:href>{$root}</d:href>
                </d:owner>
            </d:lockinfo>
            EOF;

        // Test with no Authorization header
        $response = $this->davRequest('LOCK', "{$root}/test1.txt", $xml);
        $response->assertNoContent(401);

        // Test locking the root
        $response = $this->davRequest('LOCK', $root, $xml, $john);
        $response->assertStatus(403);

        // Test locking an existing file
        $response = $this->davRequest('LOCK', "{$root}/test1.txt", $xml, $john);
        $response->assertStatus(200);

        $lock = $file->locks()->first();
        $this->assertSame("<opaquelocktoken:{$lock->token}>", $response->headers->get('Lock-Token'));
        $this->assertSame("<d:href xmlns:d=\"DAV:\">{$root}</d:href>", $lock->owner);
        $this->assertSame(1, $lock->depth);
        $this->assertSame(Lock::SCOPE_EXCLUSIVE, $lock->scope);
        $this->assertSame(1800, $lock->timeout);

        $doc = $this->responseXML($response);
        $this->assertSame('prop', $doc->documentElement->localName);
        $data = $doc->documentElement->getElementsByTagName('lockdiscovery')->item(0)
            ->getElementsByTagName('activelock')->item(0);
        $this->assertSame('write', $data->getElementsByTagName('locktype')->item(0)->firstChild->localName);
        $this->assertSame('exclusive', $data->getElementsByTagName('lockscope')->item(0)->firstChild->localName);
        $this->assertSame('1', $data->getElementsByTagName('depth')->item(0)->nodeValue);
        $this->assertSame($root, $data->getElementsByTagName('owner')->item(0)->firstChild->nodeValue);
        $this->assertSame('Second-1800', $data->getElementsByTagName('timeout')->item(0)->nodeValue);
        $this->assertSame("opaquelocktoken:{$lock->token}", $data->getElementsByTagName('locktoken')->item(0)->firstChild->nodeValue);
        $this->assertSame("/{$root}/test1.txt", $data->getElementsByTagName('lockroot')->item(0)->firstChild->nodeValue);

        // Test updating a lock (non-empty body)
        $response = $this->davRequest('LOCK', "{$root}/test1.txt", $xml, $john);
        $response->assertStatus(423);
        // TODO: Assert XML response

        // Test updating a lock - empty body as specified in RFC, but missing If header
        $response = $this->davRequest('LOCK', "{$root}/test1.txt", '', $john);
        $response->assertStatus(423);
        // TODO: Assert XML response

        // Test updating a lock - empty body as specified in RFC, with valid If header
        Carbon::setTestNow(Carbon::createFromDate(2022, 2, 2));
        $headers = ['Depth' => '0', 'If' => "(<opaquelocktoken:{$lock->token}>)"];
        $response = $this->davRequest('LOCK', "{$root}/test1.txt", '', $john, $headers);
        $response->assertStatus(200);

        $lock->refresh();
        $this->assertSame("<opaquelocktoken:{$lock->token}>", $response->headers->get('Lock-Token'));
        $this->assertSame("<d:href xmlns:d=\"DAV:\">{$root}</d:href>", $lock->owner);
        $this->assertSame(1, $lock->depth);
        $this->assertSame(Lock::SCOPE_EXCLUSIVE, $lock->scope);
        $this->assertSame(1800, $lock->timeout);
        $this->assertSame('2022-02-02', $lock->created_at->format('Y-m-d'));

        // Test non-existing location (expect an empty file created)
        $xml = <<<'EOF'
            <d:lockinfo xmlns:d='DAV:'>
                <d:lockscope><d:shared/></d:lockscope>
                <d:locktype><d:write/></d:locktype>
                <d:owner>Test Owner</d:owner>
            </d:lockinfo>
            EOF;
        $headers = ['Depth' => 'infinity', 'Timeout' => 'Infinite, Second-4100000000'];
        $response = $this->davRequest('LOCK', "{$root}/unknown", $xml, $john, $headers);
        $response->assertStatus(201);

        $doc = $this->responseXML($response);
        $file2 = $john->fsItems()->whereNot('id', $file->id)->first();
        $this->assertSame('unknown', $file2->getProperty('name'));
        $this->assertSame('0', $file2->getProperty('size'));
        $this->assertSame('application/x-empty', $file2->getProperty('mimetype'));

        $lock2 = $file2->locks()->first();
        $this->assertSame("<opaquelocktoken:{$lock2->token}>", $response->headers->get('Lock-Token'));
        $this->assertSame('Test Owner', $lock2->owner);
        $this->assertSame(Lock::DEPTH_INFINITY, $lock2->depth);
        $this->assertSame(Lock::SCOPE_SHARED, $lock2->scope);
        $this->assertSame(1800, $lock2->timeout);

        $this->assertSame('prop', $doc->documentElement->localName);
        $data = $doc->documentElement->getElementsByTagName('lockdiscovery')->item(0)
            ->getElementsByTagName('activelock')->item(0);
        $this->assertSame('shared', $data->getElementsByTagName('lockscope')->item(0)->firstChild->localName);
        $this->assertSame('infinity', $data->getElementsByTagName('depth')->item(0)->nodeValue);
        $this->assertSame('Test Owner', $data->getElementsByTagName('owner')->item(0)->nodeValue);
        $this->assertSame('Second-1800', $data->getElementsByTagName('timeout')->item(0)->nodeValue);
        $this->assertSame("opaquelocktoken:{$lock2->token}", $data->getElementsByTagName('locktoken')->item(0)->firstChild->nodeValue);
        $this->assertSame("/{$root}/unknown", $data->getElementsByTagName('lockroot')->item(0)->firstChild->nodeValue);

        // TODO: Test lock conflicts
    }

    /**
     * Test various requests to locked nodes
     */
    public function testLockFeatures(): void
    {
        // TODO: PROPFIND with lock related properties
        // TODO: Test MOVE on a locked file
        // TODO: Test COPY on a locked file
        // TODO: PUT/PATCH on a locked file
        $this->markTestIncomplete();
    }

    /**
     * Test basic MKCOL requests
     */
    public function testMkcol(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';

        // Test with no Authorization header
        $response = $this->davRequest('MKCOL', "{$root}/folder1");
        $response->assertStatus(401);

        // Test creating a collection in the root
        $response = $this->davRequest('MKCOL', "{$root}/folder1", '', $john);
        $response->assertNoContent(201);

        $this->assertCount(1, $items = $john->fsItems()->get());
        $this->assertSame('folder1', $items[0]->getProperty('name'));

        // Test collection already exists case
        $response = $this->davRequest('MKCOL', "{$root}/folder1", '', $john);
        $response->assertStatus(405);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Test creating a collection in another folder
        $response = $this->davRequest('MKCOL', "{$root}/folder1/folder2", '', $john);
        $response->assertNoContent(201);

        $this->assertCount(1, $children = $items[0]->children()->get());
        $this->assertSame($john->id, $children[0]->user_id);
        $this->assertSame('folder2', $children[0]->getProperty('name'));
    }

    /**
     * Test basic MOVE requests
     */
    public function testMove(): void
    {
        $host = trim(\config('app.url'), '/');
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';

        [$folders, $files] = $this->initTestStorage($john);

        // Test with no Authorization header
        $response = $this->davRequest('MOVE', "{$root}/test1.txt", '', null, ['Destination' => "{$host}/{$root}/moved1.txt"]);
        $response->assertStatus(401);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Test moving a non-existing file
        $response = $this->davRequest('MOVE', "{$root}/unknown", '', $john, ['Destination' => "{$host}/{$root}/moved.txt"]);
        $response->assertNoContent(404);

        // Test a file rename
        $response = $this->davRequest('MOVE', "{$root}/test1.txt", '', $john, ['Destination' => "{$host}/{$root}/moved1.txt"]);
        $response->assertNoContent(201);

        $this->assertCount(0, $files[0]->parents()->get());
        $this->assertSame('moved1.txt', $files[0]->getProperty('name'));

        // Test a folder rename
        $response = $this->davRequest('MOVE', "{$root}/folder1", '', $john, ['Destination' => "{$host}/{$root}/folder10", 'Depth' => 'infinity']);
        $response->assertNoContent(201);

        $this->assertCount(0, $folders[0]->parents()->get());
        $this->assertSame('folder10', $folders[0]->getProperty('name'));

        // Test moving a sub-folder into the root
        $response = $this->davRequest('MOVE', "{$root}/folder10/folder2", '', $john, ['Destination' => "{$host}/{$root}/folder20", 'Depth' => 'infinity']);
        $response->assertNoContent(201);

        $this->assertCount(0, $folders[1]->parents()->get());
        $this->assertSame('folder20', $folders[1]->getProperty('name'));

        // Test moving a file into the root (with no rename)
        $response = $this->davRequest('MOVE', "{$root}/folder10/test3.txt", '', $john, ['Destination' => "{$host}/{$root}/test3.txt", 'Overwrite' => 'F']);
        $response->assertNoContent(201);

        $this->assertCount(0, $files[2]->parents()->get());
        $this->assertSame('test3.txt', $files[2]->getProperty('name'));
        $this->assertSame('text/plain', $files[2]->getProperty('mimetype'));

        // Test moving a folder from root into another folder (no rename)
        $response = $this->davRequest('MOVE', "{$root}/folder20", '', $john, ['Destination' => "{$host}/{$root}/folder10/folder20", 'Depth' => 'infinity']);
        $response->assertNoContent(201);

        $this->assertSame([$folders[0]->id], $folders[1]->parents()->get()->pluck('id')->all());
        $this->assertSame('folder20', $folders[1]->getProperty('name'));

        // Test moving a file from the root into another folder
        $response = $this->davRequest('MOVE', "{$root}/test2.txt", '', $john, ['Destination' => "{$host}/{$root}/folder10/test20.txt"]);
        $response->assertNoContent(201);

        $this->assertSame([$folders[0]->id], $files[1]->parents()->get()->pluck('id')->all());
        $this->assertSame('test20.txt', $files[1]->getProperty('name'));
        $this->assertSame('text/html', $files[1]->getProperty('mimetype'));

        // Test moving into an existing location with Overwrite:F header
        $response = $this->davRequest('MOVE', "{$root}/moved1.txt", '', $john, ['Destination' => "{$host}/{$root}/test3.txt", 'Overwrite' => 'F']);
        $response->assertStatus(412);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);
    }

    /**
     * Test basic OPTIONS requests
     */
    public function testOptions(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';

        // Test with no Authorization header
        // FIXME: Looking at https://datatracker.ietf.org/doc/html/rfc3744#section-7.2.1
        // it does not seem that this should require an authenticated user, but allowing OPTIONS
        // on any location to anyone might not be the best idea either. Should we at least allow
        // unauthenticated OPTIONS on the root?
        $response = $this->davRequest('OPTIONS', $root);
        $response->assertStatus(401);

        // Test with valid Authorization header
        $response = $this->davRequest('OPTIONS', $root, '', $john);
        $response->assertNoContent(200);

        // TODO: Verify the supported feature set
        $this->assertSame('1, 3, extended-mkcol, access-control, calendarserver-principal-property-search, 2', $response->headers->get('DAV'));
    }

    /**
     * Test basic PROPFIND requests on the root location
     */
    public function testPropfindOnTheRootFolder(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';

        // Test with no Authorization header
        $response = $this->davRequest('PROPFIND', $root, '<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>');
        $response->assertStatus(401);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Test with valid Authorization header, non-existing location
        $response = $this->davRequest('PROPFIND', "{$root}/unknown", '<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>', $john);
        $response->assertStatus(404);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        [$folders, $files] = $this->initTestStorage($john);

        // Test with valid Authorization header
        // Also make sure that encoded username is working
        $enc_root = str_replace('@', '%40', $root);
        $response = $this->davRequest('PROPFIND', $enc_root, '<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>', $john);
        $response->assertStatus(207);

        $doc = $this->responseXML($response);
        $this->assertSame('multistatus', $doc->documentElement->localName);
        $this->assertCount(4, $responses = $doc->documentElement->getElementsByTagName('response'));

        // the root folder
        $this->assertSame("/{$root}/", $responses[0]->getElementsByTagName('href')->item(0)->textContent);
        $this->assertCount(1, $responses[0]->getElementsByTagName('resourcetype')->item(0)->childNodes);
        $this->assertSame('collection', $responses[0]->getElementsByTagName('resourcetype')->item(0)->firstChild->localName);
        $this->assertCount(1, $responses[0]->getElementsByTagName('prop')->item(0)->childNodes);
        $this->assertStringContainsString('200 OK', $responses[0]->getElementsByTagName('status')->item(0)->textContent);

        // the subfolder folder
        $this->assertSame("/{$root}/folder1/", $responses[1]->getElementsByTagName('href')->item(0)->textContent);
        $this->assertCount(1, $responses[1]->getElementsByTagName('resourcetype')->item(0)->childNodes);
        $this->assertSame('collection', $responses[1]->getElementsByTagName('resourcetype')->item(0)->firstChild->localName);
        $prop = $responses[1]->getElementsByTagName('prop')->item(0);
        $this->assertStringContainsString(now()->format('D'), $prop->getElementsByTagName('getlastmodified')->item(0)->textContent);
        $this->assertStringContainsString(now()->format('D'), $prop->getElementsByTagName('creationdate')->item(0)->textContent);
        $this->assertStringContainsString('200 OK', $responses[1]->getElementsByTagName('status')->item(0)->textContent);
        $this->assertCount(0, $prop->getElementsByTagName('contentlength'));
        $this->assertCount(0, $prop->getElementsByTagName('getcontenttype'));
        $this->assertCount(0, $prop->getElementsByTagName('getetag'));

        // the files
        $this->assertSame("/{$root}/test1.txt", $responses[2]->getElementsByTagName('href')->item(0)->textContent);
        $this->assertCount(0, $responses[2]->getElementsByTagName('resourcetype')->item(0)->childNodes);
        $prop = $responses[2]->getElementsByTagName('prop')->item(0);
        $this->assertStringContainsString(now()->format('D'), $prop->getElementsByTagName('getlastmodified')->item(0)->textContent);
        $this->assertStringContainsString(now()->format('D'), $prop->getElementsByTagName('creationdate')->item(0)->textContent);
        $this->assertSame('13', $prop->getElementsByTagName('getcontentlength')->item(0)->textContent);
        $this->assertSame('text/plain', $prop->getElementsByTagName('getcontenttype')->item(0)->textContent);
        $this->assertCount(1, $prop->getElementsByTagName('getetag'));
        $this->assertStringContainsString('200 OK', $responses[2]->getElementsByTagName('status')->item(0)->textContent);

        $this->assertSame("/{$root}/test2.txt", $responses[3]->getElementsByTagName('href')->item(0)->textContent);
        $this->assertCount(0, $responses[3]->getElementsByTagName('resourcetype')->item(0)->childNodes);
        $prop = $responses[3]->getElementsByTagName('prop')->item(0);
        $this->assertStringContainsString(now()->format('D'), $prop->getElementsByTagName('getlastmodified')->item(0)->textContent);
        $this->assertStringContainsString(now()->format('D'), $prop->getElementsByTagName('creationdate')->item(0)->textContent);
        $this->assertSame('22', $prop->getElementsByTagName('getcontentlength')->item(0)->textContent);
        $this->assertSame('text/html', $prop->getElementsByTagName('getcontenttype')->item(0)->textContent);
        $this->assertCount(1, $prop->getElementsByTagName('getetag'));
        $this->assertStringContainsString('200 OK', $responses[3]->getElementsByTagName('status')->item(0)->textContent);

        // Test Depth:0
        $response = $this->davRequest('PROPFIND', $root, '<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>', $john, ['Depth' => '0']);
        $response->assertStatus(207);

        $doc = $this->responseXML($response);
        $this->assertSame('multistatus', $doc->documentElement->localName);
        $this->assertCount(1, $responses = $doc->documentElement->getElementsByTagName('response'));
        $this->assertSame("/{$root}/", $doc->getElementsByTagName('href')->item(0)->textContent);

        // Test that Depth:infinity is not supported
        // FIXME: Seems Sabre falls back to Depth:1 and does not respond with an error
        $response = $this->davRequest('PROPFIND', $root, '<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>', $john, ['Depth' => 'infinity']);
        $response->assertStatus(207);

        $doc = $this->responseXML($response);
        $this->assertSame('multistatus', $doc->documentElement->localName);
        $this->assertCount(4, $responses = $doc->documentElement->getElementsByTagName('response'));
    }

    /**
     * Test basic PROPFIND requests on non-root folder
     */
    public function testPropfindOnCustomFolder(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';

        [$folders, $files] = $this->initTestStorage($john);

        // Test with no Authorization header
        $response = $this->davRequest('PROPFIND', "{$root}/folder1", '<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>');
        $response->assertStatus(401);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Test with valid Authorization header
        $response = $this->davRequest('PROPFIND', "{$root}/folder1", '<d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>', $john);
        $response->assertStatus(207);

        $doc = $this->responseXML($response);
        $this->assertSame('multistatus', $doc->documentElement->localName);
        $this->assertCount(4, $responses = $doc->documentElement->getElementsByTagName('response'));

        $this->assertSame("/{$root}/folder1/", $responses[0]->getElementsByTagName('href')->item(0)->textContent);
        $this->assertSame("/{$root}/folder1/folder2/", $responses[1]->getElementsByTagName('href')->item(0)->textContent);
        $this->assertSame("/{$root}/folder1/test3.txt", $responses[2]->getElementsByTagName('href')->item(0)->textContent);
        $this->assertSame("/{$root}/folder1/test4.txt", $responses[3]->getElementsByTagName('href')->item(0)->textContent);
    }

    /**
     * Test basic PUT requests
     */
    public function testPut(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';

        // Test with no Authorization header
        $response = $this->davRequest('PUT', "{$root}/test.txt", 'Test', null, ['Content-Type' => 'text/plain']);
        $response->assertStatus(401);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Test creating a file in the root folder (non-empty file)
        $response = $this->davRequest('PUT', "{$root}/test.txt", 'Test', $john, ['Content-Type' => 'text/plain']);
        $response->assertNoContent(201);
        $response->assertHeader('ETag');

        $this->assertCount(1, $files = $john->fsItems()->where('type', Item::TYPE_FILE)->get());
        $this->assertSame('test.txt', $files[0]->getProperty('name'));
        $this->assertSame('4', $files[0]->getProperty('size'));
        $this->assertSame('text/plain', $files[0]->getProperty('mimetype'));
        $this->assertSame('Test', $this->getTestFileContent($files[0]));

        // Test updating a file in the root folder (empty file)
        $response = $this->davRequest('PUT', "{$root}/test.txt", '', $john, ['Content-Type' => 'text/plain']);
        $response->assertNoContent(204);
        $response->assertHeader('ETag');

        $this->assertSame('test.txt', $files[0]->getProperty('name'));
        $this->assertSame('0', $files[0]->getProperty('size'));
        $this->assertSame('application/x-empty', $files[0]->getProperty('mimetype'));
        $this->assertSame('', $this->getTestFileContent($files[0]));

        // Test updating a file in the root folder (non-empty file)
        $response = $this->davRequest('PUT', "{$root}/test.txt", 'Test222', $john, ['Content-Type' => 'text/plain']);
        $response->assertNoContent(204);
        $response->assertHeader('ETag');

        $this->assertSame('test.txt', $files[0]->getProperty('name'));
        $this->assertSame('7', $files[0]->getProperty('size'));
        $this->assertSame('text/plain', $files[0]->getProperty('mimetype'));
        $this->assertSame('Test222', $this->getTestFileContent($files[0]));

        $files[0]->delete();
        $folder = $this->getTestCollection($john, 'folder1');

        // Test creating a file in custom folder
        $response = $this->davRequest('PUT', "{$root}/folder1/test.txt", '<html>aaa</html>', $john, ['Content-Type' => 'text/plain']);
        $response->assertNoContent(201);
        $response->assertHeader('ETag');

        $this->assertCount(1, $files = $folder->children()->where('type', Item::TYPE_FILE)->get());
        $this->assertSame('test.txt', $files[0]->getProperty('name'));
        $this->assertSame('16', $files[0]->getProperty('size'));
        $this->assertSame('text/html', $files[0]->getProperty('mimetype'));
        $this->assertSame('<html>aaa</html>', $this->getTestFileContent($files[0]));

        // Test updating a file in custom folder
        $response = $this->davRequest('PUT', "{$root}/folder1/test.txt", 'Test', $john, ['Content-Type' => 'text/plain']);
        $response->assertNoContent(204);
        $response->assertHeader('ETag');

        $this->assertSame('test.txt', $files[0]->getProperty('name'));
        $this->assertSame('4', $files[0]->getProperty('size'));
        $this->assertSame('text/plain', $files[0]->getProperty('mimetype'));
        $this->assertSame('Test', $this->getTestFileContent($files[0]));
    }

    /**
     * Test basic PROPPATCH requests
     */
    public function testProppatch(): void
    {
        $xml = <<<'EOF'
            <d:propertyupdate xmlns:d="DAV:" xmlns:o="urn:schemas-microsoft-com:office:office">
                <d:set>
                    <d:prop>
                        <o:Author>John Doe</o:Author>
                    </d:prop>
                </d:set>
            </d:propertyupdate>
            EOF;

        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';

        // Test with no Authorization header
        $response = $this->davRequest('PROPPATCH', $root, $xml);
        $response->assertStatus(401);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Test a PROPPATCH on the root
        $response = $this->davRequest('PROPPATCH', $root, $xml, $john);
        $response->assertStatus(207);

        $doc = $this->responseXML($response);
        $this->assertSame('multistatus', $doc->documentElement->localName);
        $this->assertCount(1, $doc->getElementsByTagName('response'));
        $this->assertSame('HTTP/1.1 403 Forbidden', $doc->getElementsByTagName('status')->item(0)->textContent);

        // Note: We don't support any properties in PROPPATCH yet
    }

    /**
     * Test basic REPORT requests
     */
    public function testReport(): void
    {
        $xml = <<<'EOF'
            <D:version-tree xmlns:D="DAV:">
                <D:prop>
                    <D:version-name/>
                    <D:creator-displayname/>
                    <D:successor-set/>
                </D:prop>
            </D:version-tree>
            EOF;

        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';

        // Test with no Authorization header
        $response = $this->davRequest('REPORT', $root, $xml);
        $response->assertStatus(401);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);

        // Test a REPORT on the root, this report is not supported
        $response = $this->davRequest('REPORT', $root, $xml, $john);
        $response->assertStatus(415);

        $doc = $this->responseXML($response);
        $this->assertSame('error', $doc->documentElement->localName);
    }

    /**
     * Test basic UNLOCK requests (RFC 4918)
     */
    public function testUnlock(): void
    {
        $john = $this->getTestUser('john@kolab.org');
        $root = trim(\config('services.dav.webdav_root'), '/') . '/user/john@kolab.org';
        $file = $this->getTestFile($john, 'test1.txt', 'Test content1', ['mimetype' => 'text/plain']);

        // Test with no Authorization header
        $response = $this->davRequest('UNLOCK', "{$root}/test1.txt");
        $response->assertNoContent(401);

        // Test unlocking a file that is not locked
        $response = $this->davRequest('UNLOCK', "{$root}/test1.txt", '', $john, ['Lock-Token' => "<opaquelocktoken:test>"]);
        $response->assertStatus(409);
        // TODO: Assert XML response

        $lock = $file->locks()->create([
            'token' => 'testtoken',
            'depth' => 0,
            'scope' => Lock::SCOPE_SHARED,
            'timeout' => 1800,
            'owner' => 'test',
        ]);

        // Test unlocking a locked file (no Lock-Token header)
        $response = $this->davRequest('UNLOCK', "{$root}/test1.txt", '', $john);
        $response->assertNoContent(400);

        // Test unlocking a locked file
        $response = $this->davRequest('UNLOCK', "{$root}/test1.txt", '', $john, ['Lock-Token' => "<opaquelocktoken:{$lock->token}>"]);
        $response->assertNoContent(204);

        $this->assertCount(0, $file->locks()->get());
    }

    /**
     * Do a HTTP request
     */
    protected function davRequest($method, $url, $xml = '', $user = null, $headers = [])
    {
        if ($xml && empty($headers['Content-Type']) && !str_starts_with($xml, '<?xml')) {
            $xml = '<?xml version="1.0" encoding="utf-8" ?>' . $xml;
        }

        $default_headers = [
            'Content-Type' => 'text/xml; charset="utf-8"',
            'Content-Length' => strlen($xml),
            'Depth' => '1',
        ];

        if ($user) {
            $default_headers['Authorization'] = 'Basic ' . base64_encode($user->email . ':' . \config('app.passphrase'));
        }

        $server = $this->transformHeadersToServerVars($headers + $default_headers);

        // When testing Context is not being reset, we have to do this manually
        // https://github.com/laravel/framework/issues/57776
        Context::flush();

        return $this->call($method, $url, [], [], [], $server, $xml);
    }

    /**
     * Parse the response XML
     */
    protected function responseXML($response): \DOMDocument
    {
        $body = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : $response->getContent();

        if (empty($body)) {
            $body = '<?xml version="1.0" encoding="utf-8" ?>';
        }

        // Remove space between tags for easier output handling
        $body = preg_replace('/>[\s\t\r\n]+</', '><', $body);

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadXML($body);
        $doc->formatOutput = true;

        return $doc;
    }

    /**
     * Initialize test folders/files in the storage
     */
    protected function initTestStorage(User $user): array
    {
        /*
            /test1.txt
            /test2.txt
            /folder1/
            /folder1/folder2/
            /folder1/test3.txt
            /folder1/test4.txt
         */
        $folders = $files = [];

        $folders[] = $this->getTestCollection($user, 'folder1');
        $folders[] = $this->getTestCollection($user, 'folder2');
        $files[] = $this->getTestFile($user, 'test1.txt', 'Test content1', ['mimetype' => 'text/plain']);
        $files[] = $this->getTestFile($user, 'test2.txt', '<html>Test con2</html>', ['mimetype' => 'text/html']);
        $files[] = $this->getTestFile($user, 'test3.txt', 'Test content3', ['mimetype' => 'text/plain']);
        $files[] = $this->getTestFile($user, 'test4.txt', '<p>Test con4</p>', ['mimetype' => 'text/html']);

        $folders[0]->children()->attach($folders[1]);
        $folders[0]->children()->attach($files[2]);
        $folders[0]->children()->attach($files[3]);

        return [$folders, $files];
    }
}
