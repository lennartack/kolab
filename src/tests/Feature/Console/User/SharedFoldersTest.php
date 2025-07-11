<?php

namespace Tests\Feature\Console\User;

use Tests\TestCase;

class SharedFoldersTest extends TestCase
{
    /**
     * Test command runs
     */
    public function testHandle(): void
    {
        $code = \Artisan::call("user:shared-folders unknown");
        $output = trim(\Artisan::output());

        $this->assertSame(1, $code);
        $this->assertSame("No such user unknown", $output);

        $count = in_array('event', config('app.shared_folder_types')) ? 3 : 1;

        $code = \Artisan::call("user:shared-folders john@kolab.org --attr=name");
        $output = trim(\Artisan::output());

        $this->assertSame(0, $code);
        $this->assertCount($count, explode("\n", $output));

        if ($count == 3) {
            $folder1 = $this->getTestSharedFolder('folder-event@kolab.org');
            $folder2 = $this->getTestSharedFolder('folder-contact@kolab.org');
            $folder3 = $this->getTestSharedFolder('folder-mail@kolab.org');

            $this->assertStringContainsString("{$folder1->id} {$folder1->name}", $output);
            $this->assertStringContainsString("{$folder2->id} {$folder2->name}", $output);
            $this->assertStringContainsString("{$folder3->id} {$folder3->name}", $output);
        } else {
            $folder = $this->getTestSharedFolder('folder-mail@kolab.org');
            $this->assertStringContainsString("{$folder->id} {$folder->name}", $output);
        }
    }
}
